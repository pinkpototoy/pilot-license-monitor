<?php

namespace App\Domain\Records;

use App\Domain\Audit\AuditLogger;
use App\Domain\Clock;
use App\Domain\Compliance\ComplianceEngine;
use App\Domain\DomainRuleViolation;
use App\Domain\Notifications\NotificationService;
use App\Enums\PeriodSource;
use App\Enums\Role;
use App\Enums\ValidityStatus;
use App\Models\Credential;
use App\Models\CredentialPeriod;
use App\Models\CredentialType;
use App\Models\StatusOverride;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** UC-05, UC-17 — credentials and validity periods (FR-020 to FR-027, BR-010 to BR-018). */
class CredentialService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ComplianceEngine $engine,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Record a credential with its first validity period.
     *
     * @param  bool  $confirmed  the user confirmed a BR-013 warning (validity longer than the type's maximum)
     */
    public function record(
        Student $student,
        CredentialType $type,
        ?string $licenseNumber,
        ?string $issueDate,
        ?string $expiryDate,
        User $actor,
        PeriodSource $source = PeriodSource::Manual,
        bool $confirmed = false,
        ?int $importBatchId = null,
    ): Credential {
        $licenseNumber = $this->cleanNumber($licenseNumber);
        [$issue, $expiry] = $this->validateDates($type, $issueDate, $expiryDate, $confirmed);
        $this->validateNumber($type, $licenseNumber);

        // BR-015: one current credential per type.
        if ($student->currentCredentials()->where('credential_type_id', $type->id)->exists()) {
            throw DomainRuleViolation::on('credential_type_id',
                "BR-015: {$student->fullName()} already has a current {$type->name}. Record a new period on it instead.");
        }

        return DB::transaction(function () use ($student, $type, $licenseNumber, $issue, $expiry, $actor, $source, $importBatchId) {
            $credential = Credential::create([
                'student_id' => $student->id,
                'credential_type_id' => $type->id,
                'license_number' => $licenseNumber,
                'created_by' => $actor->id,
            ]);
            $period = $credential->periods()->create([
                'license_number' => $licenseNumber,
                'issue_date' => $issue?->toDateString(),
                'expiry_date' => $expiry?->toDateString(),
                'source' => $source,
                'import_batch_id' => $importBatchId,
                'created_by' => $actor->id,
            ]);
            $credential->forceFill(['current_period_id' => $period->id])->save();

            $this->audit->record('credential.created', 'credential', $credential->id, $student->id, [
                'credential_type' => ['old' => null, 'new' => $type->code],
                'license_number' => ['old' => null, 'new' => $licenseNumber],
                'issue_date' => ['old' => null, 'new' => $issue?->toDateString()],
                'expiry_date' => ['old' => null, 'new' => $expiry?->toDateString()],
            ], $source === PeriodSource::Import ? "Import batch #{$importBatchId}" : null, $actor);

            $this->afterChange($credential);

            return $credential->refresh();
        });
    }

    /**
     * BR-017 / EC-09: correct the CURRENT period's dates or number. Requires a reason,
     * audits before/after, ends any override (BR-018) and recomputes status.
     */
    public function correctCurrentPeriod(
        Credential $credential,
        ?string $licenseNumber,
        ?string $issueDate,
        ?string $expiryDate,
        string $reason,
        User $actor,
        bool $confirmed = false,
    ): Credential {
        if (trim($reason) === '') {
            throw DomainRuleViolation::on('reason', 'BR-017: A reason is required when changing license dates.');
        }
        $credential->loadMissing(['type', 'currentPeriod']);
        $period = $credential->currentPeriod ?? throw DomainRuleViolation::on('credential', 'This credential has no current period to correct.');

        $licenseNumber = $this->cleanNumber($licenseNumber);
        [$issue, $expiry] = $this->validateDates($credential->type, $issueDate, $expiryDate, $confirmed);
        $this->validateNumber($credential->type, $licenseNumber, $credential->id);

        return DB::transaction(function () use ($credential, $period, $licenseNumber, $issue, $expiry, $reason, $actor) {
            $before = ['license_number' => $period->license_number, 'issue_date' => $period->issue_date, 'expiry_date' => $period->expiry_date];
            $period->fill([
                'license_number' => $licenseNumber,
                'issue_date' => $issue?->toDateString(),
                'expiry_date' => $expiry?->toDateString(),
            ])->save();
            $changes = AuditLogger::diff($before, ['license_number' => $licenseNumber, 'issue_date' => $issue, 'expiry_date' => $expiry]);
            if ($changes === []) {
                return $credential;
            }

            $credential->forceFill(['license_number' => $licenseNumber, 'version' => $credential->version + 1])->save();
            $this->audit->record('credential.period_corrected', 'credential', $credential->id, $credential->student_id, $changes, $reason, $actor);
            $this->notifications->cancelPendingForCredential($credential);   // BR-032
            $this->endOverride($credential, $actor, 'Dates changed (BR-018)');
            $this->afterChange($credential);

            return $credential->refresh();
        });
    }

    /**
     * FR-023 / EC-15 / EC-16: add a NEW validity period; the old one is kept and marked superseded.
     * Also used by the Phase 3 renewal workflow (source = renewal).
     */
    public function addPeriod(
        Credential $credential,
        ?string $licenseNumber,
        ?string $issueDate,
        ?string $expiryDate,
        User $actor,
        PeriodSource $source = PeriodSource::Manual,
        ?int $renewalCaseId = null,
        ?string $reason = null,
        bool $confirmed = false,
    ): CredentialPeriod {
        $credential->loadMissing(['type', 'currentPeriod']);
        $licenseNumber = $this->cleanNumber($licenseNumber) ?? $credential->license_number;
        [$issue, $expiry] = $this->validateDates($credential->type, $issueDate, $expiryDate, $confirmed);
        $this->validateNumber($credential->type, $licenseNumber, $credential->id);

        $old = $credential->currentPeriod;
        if ($old?->expiry_date && $expiry && ! $expiry->greaterThan($old->expiry_date) && ! $confirmed) {
            throw DomainRuleViolation::on('expiry_date',
                'UC-14: The new expiry date is not later than the current one ('.$old->expiry_date->format('d M Y').'). Confirm to continue.', true);
        }

        return DB::transaction(function () use ($credential, $old, $licenseNumber, $issue, $expiry, $actor, $source, $renewalCaseId, $reason) {
            // Supersede first: the database allows only one current period per credential.
            $old?->forceFill(['superseded_at' => now()])->save();
            $new = $credential->periods()->create([
                'license_number' => $licenseNumber,
                'issue_date' => $issue?->toDateString(),
                'expiry_date' => $expiry?->toDateString(),
                'source' => $source,
                'renewal_case_id' => $renewalCaseId,
                'created_by' => $actor->id,
            ]);
            $old?->forceFill(['superseded_by' => $new->id])->save();
            $credential->forceFill([
                'current_period_id' => $new->id,
                'license_number' => $licenseNumber,
                'version' => $credential->version + 1,
            ])->save();

            $this->audit->record('credential.period_added', 'credential', $credential->id, $credential->student_id, [
                'license_number' => ['old' => $old?->license_number, 'new' => $licenseNumber],
                'issue_date' => ['old' => $old?->issue_date?->toDateString(), 'new' => $issue?->toDateString()],
                'expiry_date' => ['old' => $old?->expiry_date?->toDateString(), 'new' => $expiry?->toDateString()],
            ], $reason, $actor);

            $this->notifications->cancelPendingForCredential($credential);   // BR-032
            $this->endOverride($credential, $actor, 'New validity period recorded (BR-018)');
            $this->afterChange($credential);

            return $new;
        });
    }

    /** BR-018 / UC-17: Admin-only, reasoned, time-limited override of the computed status. */
    public function applyOverride(Credential $credential, ValidityStatus $status, string $reason, ?string $validUntil, User $actor): StatusOverride
    {
        if ($actor->role !== Role::Admin) {
            throw DomainRuleViolation::on('override_status', 'BR-018: Only an Admin can override a computed status.');
        }
        if (trim($reason) === '') {
            throw DomainRuleViolation::on('reason', 'BR-018: A reason is required for a status override.');
        }
        $until = $validUntil ? CarbonImmutable::parse($validUntil, Clock::timezone()) : null;
        if ($until && $until->lt(Clock::today())) {
            throw DomainRuleViolation::on('valid_until', 'The override end date cannot be in the past.');
        }

        return DB::transaction(function () use ($credential, $status, $reason, $until, $actor) {
            $this->endOverride($credential, $actor, 'Replaced by a new override');
            $override = $credential->overrides()->create([
                'override_status' => $status,
                'reason' => $reason,
                'valid_until' => $until?->toDateString(),
                'created_by' => $actor->id,
            ]);
            $this->audit->record('credential.override_applied', 'credential', $credential->id, $credential->student_id,
                ['override_status' => ['old' => null, 'new' => $status->value], 'valid_until' => ['old' => null, 'new' => $until?->toDateString()]],
                $reason, $actor);
            $credential->unsetRelation('activeOverride');
            $this->afterChange($credential);

            return $override;
        });
    }

    public function endOverride(Credential $credential, User $actor, string $reason): void
    {
        $open = $credential->activeOverride()->first();
        if (! $open) {
            return;
        }
        $open->forceFill(['ended_at' => now(), 'end_reason' => $reason])->save();
        $this->audit->record('credential.override_ended', 'credential', $credential->id, $credential->student_id,
            ['override_status' => ['old' => $open->override_status->value, 'new' => null]], $reason, $actor);
        $credential->unsetRelation('activeOverride');
    }

    private function afterChange(Credential $credential): void
    {
        $credential->unsetRelation('currentPeriod');
        $credential->unsetRelation('activeOverride');
        $this->engine->evaluateStudent($credential->student()->first());
    }

    /** @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable} */
    private function validateDates(CredentialType $type, ?string $issueDate, ?string $expiryDate, bool $confirmed): array
    {
        $tz = Clock::timezone();
        $errors = [];
        $issue = $this->parseDate($issueDate, 'issue_date', $errors, $tz);
        $expiry = $this->parseDate($expiryDate, 'expiry_date', $errors, $tz);

        if ($issue && $issue->gt(Clock::today()->addDay())) {
            $errors['issue_date'] = 'BR-012: The issue date cannot be more than 1 day in the future.';
        }
        if ($issue && $expiry && ! $expiry->gt($issue)) {
            $errors['expiry_date'] = 'BR-011: The expiry date must be after the issue date.';
        }
        if (! $type->expires && $expiry) {
            $errors['expiry_date'] = "{$type->name} does not expire; leave the expiry date empty.";
        }
        if ($errors) {
            throw new DomainRuleViolation($errors);
        }

        // BR-013: unusually long validity needs explicit confirmation.
        if ($issue && $expiry && $type->max_validity_months && ! $confirmed
            && $expiry->gt($issue->addMonths($type->max_validity_months))) {
            throw DomainRuleViolation::on('expiry_date',
                "BR-013: This validity period is longer than the {$type->max_validity_months}-month maximum configured for {$type->name}. Check the dates, then confirm to save.", true);
        }
        // BR-010 is not an error: a missing expiry date is saved and flagged as Incomplete Data.

        return [$issue, $expiry];
    }

    private function validateNumber(CredentialType $type, ?string $number, ?int $ignoreCredentialId = null): void
    {
        if ($number !== null && ! $type->acceptsNumber($number)) {
            throw DomainRuleViolation::on('license_number', "FR-022 / EC-02: This doesn't match the license number format configured for {$type->name}.");
        }
        // BR-014 / EC-04: the same number cannot belong to two credentials of one type.
        if ($number !== null && Credential::query()
            ->where('credential_type_id', $type->id)
            ->whereRaw('upper(license_number) = upper(?)', [$number])
            ->when($ignoreCredentialId, fn ($q) => $q->where('id', '<>', $ignoreCredentialId))
            ->exists()) {
            throw DomainRuleViolation::on('license_number', "BR-014: License number {$number} is already recorded for another student.");
        }
    }

    private function parseDate(?string $value, string $field, array &$errors, string $tz): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        // ISO only: day/month ambiguity is the most common spreadsheet error (SRS 28.3).
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value))) {
            $errors[$field] = 'Use the date picker or the format YYYY-MM-DD.';

            return null;
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d', trim($value), $tz);
        if (! $date || $date->format('Y-m-d') !== trim($value)) {
            $errors[$field] = 'This is not a valid calendar date.';

            return null;
        }

        return $date;
    }

    private function cleanNumber(?string $number): ?string
    {
        $number = $number === null ? null : mb_strtoupper(preg_replace('/\s+/', '', trim($number)));

        return $number === '' ? null : $number;
    }
}
