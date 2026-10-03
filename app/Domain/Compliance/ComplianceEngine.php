<?php

namespace App\Domain\Compliance;

use App\Domain\Audit\AuditLogger;
use App\Domain\Clock;
use App\Enums\ComplianceState;
use App\Enums\ValidityStatus;
use App\Models\ComplianceSnapshot;
use App\Models\Credential;
use App\Models\CredentialPeriod;
use App\Models\JobRun;
use App\Models\Student;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SRS 13 & 16 — recomputes credential validity and student compliance.
 * Runs immediately after any change (FR-025) and nightly for every student,
 * writing a daily snapshot for point-in-time reports.
 */
class ComplianceEngine
{
    public function __construct(
        private readonly ValidityCalculator $calculator,
        private readonly ComplianceEvaluator $evaluator,
        private readonly AuditLogger $audit,
    ) {}

    public function statusFor(Credential $credential, ?CarbonImmutable $today = null): ValidityStatus
    {
        $today ??= Clock::today();
        $credential->loadMissing(['type', 'currentPeriod', 'activeOverride']);

        // BR-018: an open, unexpired Admin override wins.
        $override = $credential->activeOverride;
        if ($override && ($override->valid_until === null || ! $override->valid_until->lt($today))) {
            return $override->override_status;
        }

        $period = $credential->currentPeriod;
        $status = $this->fromPeriod($credential, $period, $today);

        // A renewal recorded early: the superseded period still governs until the new one starts.
        if ($status === ValidityStatus::NotYetValid && $period) {
            $previous = CredentialPeriod::where('superseded_by', $period->id)->first();
            if ($previous) {
                $status = $this->fromPeriod($credential, $previous, $today);
            }
        }

        return $status;
    }

    public function evaluateCredential(Credential $credential, ?CarbonImmutable $today = null): ValidityStatus
    {
        $status = $this->statusFor($credential, $today);
        $old = $credential->current_status;
        $first = $credential->status_evaluated_at === null;

        if ($first) {
            // Initial calculation of a new record: not a change, so no separate audit entry.
            $credential->forceFill(['current_status' => $status, 'status_evaluated_at' => now()])->saveQuietly();
        } elseif ($old !== $status) {
            DB::transaction(function () use ($credential, $status, $old) {
                $credential->forceFill(['current_status' => $status, 'status_evaluated_at' => now()])->saveQuietly();
                $this->audit->record(
                    'credential.status_changed', 'credential', $credential->id, $credential->student_id,
                    ['current_status' => ['old' => $old?->value, 'new' => $status->value]],
                    'Recomputed by compliance engine', actor: null,
                );
            });
        } else {
            $credential->forceFill(['status_evaluated_at' => now()])->saveQuietly();
        }

        return $status;
    }

    public function evaluateStudent(Student $student, ?CarbonImmutable $today = null): ComplianceState
    {
        $today ??= Clock::today();
        $student->loadMissing(['program.requiredCredentialTypes']);

        $statusByType = [];
        foreach ($student->currentCredentials()->with(['type', 'currentPeriod', 'activeOverride'])->get() as $credential) {
            $statusByType[$credential->credential_type_id] = $this->evaluateCredential($credential, $today);
        }

        $required = $student->program?->requiredCredentialTypes->pluck('id')->map(fn ($id) => (int) $id)->all();
        $state = $this->evaluator->evaluate($student->status->isMonitored(), $required, $statusByType);

        $old = $student->compliance_state;
        if ($student->compliance_evaluated_at === null) {
            $student->forceFill(['compliance_state' => $state, 'compliance_evaluated_at' => now()])->saveQuietly();
        } elseif ($old !== $state) {
            DB::transaction(function () use ($student, $state, $old) {
                $student->forceFill(['compliance_state' => $state, 'compliance_evaluated_at' => now()])->saveQuietly();
                $this->audit->record(
                    'student.compliance_changed', 'student', $student->id, $student->id,
                    ['compliance_state' => ['old' => $old?->value, 'new' => $state->value]],
                    'Recomputed by compliance engine', actor: null,
                );
            });
        } else {
            $student->forceFill(['compliance_evaluated_at' => now()])->saveQuietly();
        }

        return $state;
    }

    /**
     * UC-08 nightly evaluation. Idempotent: re-running on the same date overwrites
     * that date's snapshot rows rather than duplicating them.
     *
     * @return array{students: int, credentials: int, by_state: array<string, int>}
     */
    public function runNightly(?CarbonImmutable $today = null): array
    {
        $today ??= Clock::today();
        $run = JobRun::create(['job_name' => 'compliance:evaluate', 'started_at' => now()]);
        $summary = ['students' => 0, 'credentials' => 0, 'by_state' => []];

        try {
            Student::query()->with('program.requiredCredentialTypes')->orderBy('id')
                ->chunkById(200, function ($students) use ($today, &$summary) {
                    foreach ($students as $student) {
                        $state = $this->evaluateStudent($student, $today);
                        $summary['students']++;
                        $summary['by_state'][$state->value] = ($summary['by_state'][$state->value] ?? 0) + 1;
                        $summary['credentials'] += $this->snapshot($student, $state, $today);
                    }
                });

            $run->update(['finished_at' => now(), 'status' => 'succeeded', 'items_processed' => $summary['students'], 'summary' => $summary]);
        } catch (Throwable $e) {
            $run->update(['finished_at' => now(), 'status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000), 'summary' => $summary]);
            throw $e;
        }

        return $summary;
    }

    private function snapshot(Student $student, ComplianceState $state, CarbonImmutable $today): int
    {
        $rows = [];
        foreach ($student->currentCredentials()->with(['currentPeriod', 'openRenewalCase'])->get() as $credential) {
            $rows[] = [
                'snapshot_date' => $today->toDateString(),
                'student_id' => $student->id,
                'credential_id' => $credential->id,
                'validity_status' => $credential->current_status->value,
                'compliance_state' => $state->value,
                'case_status' => $credential->openRenewalCase?->status?->value,
                'expiry_date' => $credential->currentPeriod?->expiry_date?->toDateString(),
                'created_at' => now(),
            ];
        }
        if ($rows) {
            ComplianceSnapshot::upsert($rows, ['snapshot_date', 'credential_id'],
                ['validity_status', 'compliance_state', 'case_status', 'expiry_date', 'created_at']);
        }

        return count($rows);
    }

    private function fromPeriod(Credential $credential, ?CredentialPeriod $period, CarbonImmutable $today): ValidityStatus
    {
        $type = $credential->type;

        return $this->calculator->calculate(
            typeExpires: $type->expires,
            expiringSoonDays: $type->expiring_soon_days ?: config('splms.default_expiring_soon_days'),
            licenseNumber: $period?->license_number ?? $credential->license_number,
            issueDate: $period?->issue_date,
            expiryDate: $period?->expiry_date,
            today: $today,
            hasPeriod: $period !== null,
        );
    }
}
