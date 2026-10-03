<?php

namespace App\Domain\Reports;

use App\Domain\Clock;
use App\Domain\Compliance\ValidityCalculator;
use App\Enums\ComplianceState;
use App\Enums\RenewalCaseStatus;
use App\Enums\Role;
use App\Enums\ValidityStatus;
use App\Models\AuditLog;
use App\Models\ComplianceSnapshot;
use App\Models\Credential;
use App\Models\Document;
use App\Models\OutboundNotification;
use App\Models\RenewalCase;
use App\Models\Student;
use App\Models\User;
use App\Support\AuditPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/** SRS 29 — the report catalogue (RPT-01 to RPT-10). Each report = columns + rows for given filters. */
class ReportService
{
    public const CATALOGUE = [
        'RPT-01' => ['Active credentials', 'Credentials currently valid, with days remaining.', ['type', 'program', 'cohort']],
        'RPT-02' => ['Expiring soon', 'Credentials expiring within a chosen number of days, and whether a renewal is underway.', ['type', 'program', 'cohort', 'days']],
        'RPT-03' => ['Expired credentials', 'Expired credentials with days overdue, renewal status and the last reminder sent.', ['type', 'program', 'cohort']],
        'RPT-04' => ['Pending renewals and verification', 'Open renewal cases by status and age.', ['type', 'program', 'cohort']],
        'RPT-05' => ['Rejected documents', 'Rejected documents with reason, reviewer and whether a correction followed.', ['type', 'from', 'to']],
        'RPT-06' => ['Student compliance', 'Each student\'s compliance state. Choose a past date to see the state on that day.', ['program', 'cohort', 'as_of', 'state']],
        'RPT-07' => ['Renewal history', 'Completed renewals with turnaround time.', ['type', 'program', 'from', 'to']],
        'RPT-08' => ['Notification history', 'Messages sent, failed or suppressed, by channel and type.', ['from', 'to', 'channel', 'notification_status']],
        'RPT-09' => ['Data quality', 'Records that need fixing: missing dates, programs, required credentials or invalid emails.', ['program']],
        'RPT-10' => ['Audit extract', 'The audit log for a period (Admin only).', ['from', 'to']],
    ];

    public function canRun(User $user, string $code): bool
    {
        return isset(self::CATALOGUE[$code])
            && ($code === 'RPT-10' ? $user->role === Role::Admin : $user->hasRole(Role::Admin, Role::Staff, Role::Viewer));
    }

    /** @return array{title: string, columns: array<string,string>, rows: Collection<int, array>, filters: array} */
    public function run(string $code, array $f): array
    {
        $today = Clock::today();
        $f = array_filter($f, fn ($v) => $v !== null && $v !== '');
        [$columns, $rows] = match ($code) {
            'RPT-01' => $this->credentials($f, $today, 'active'),
            'RPT-02' => $this->expiring($f, $today),
            'RPT-03' => $this->credentials($f, $today, 'expired'),
            'RPT-04' => $this->pending($f, $today),
            'RPT-05' => $this->rejected($f),
            'RPT-06' => $this->compliance($f, $today),
            'RPT-07' => $this->history($f),
            'RPT-08' => $this->notifications($f),
            'RPT-09' => $this->dataQuality($f),
            'RPT-10' => $this->audit($f),
        };

        return ['title' => self::CATALOGUE[$code][0], 'columns' => $columns, 'rows' => $rows, 'filters' => $f];
    }

    private function credentialQuery(array $f): Builder
    {
        return Credential::query()->whereNull('archived_at')
            ->whereHas('student', fn ($q) => $q->monitored()
                ->when($f['program'] ?? null, fn ($w, $v) => $w->where('program_id', $v))
                ->when($f['cohort'] ?? null, fn ($w, $v) => $w->where('cohort', $v)))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('credential_type_id', $v))
            ->with(['student.program', 'type', 'currentPeriod', 'openRenewalCase']);
    }

    private function base(Credential $c, CarbonImmutable $today): array
    {
        $exp = $c->currentPeriod?->expiry_date;

        return [
            'student_number' => $c->student->student_number,
            'student' => $c->student->fullName(),
            'program' => $c->student->program?->code ?? '',
            'cohort' => $c->student->cohort ?? '',
            'credential' => $c->type->name,
            'license_number' => $c->license_number ?? '',
            'issue_date' => $c->currentPeriod?->issue_date?->format('Y-m-d') ?? '',
            'expiry_date' => $exp?->format('Y-m-d') ?? '',
            'days' => $exp ? ValidityCalculator::daysRemaining($exp, $today) : null,
        ];
    }

    private const BASE_COLS = ['student_number' => 'Student no.', 'student' => 'Student', 'program' => 'Program', 'cohort' => 'Cohort',
        'credential' => 'Credential', 'license_number' => 'License no.', 'issue_date' => 'Issued', 'expiry_date' => 'Expires'];

    private function credentials(array $f, CarbonImmutable $today, string $status): array
    {
        $rows = $this->credentialQuery($f)->where('current_status', $status)->get()
            ->map(function (Credential $c) use ($today, $status) {
                $r = $this->base($c, $today);
                if ($status === 'expired') {
                    $r['days'] = abs((int) $r['days']);
                    $r['case_status'] = $c->openRenewalCase?->status->label() ?? 'None';
                    $r['last_reminder'] = OutboundNotification::where('period_id', $c->current_period_id)->where('status', 'sent')
                        ->where('event_type', 'expiry_reminder')->max('sent_at') ?? '';
                    if ($r['last_reminder']) {
                        $r['last_reminder'] = CarbonImmutable::parse($r['last_reminder'])->timezone(Clock::timezone())->format('Y-m-d');
                    }
                }

                return $r;
            })->sortBy('expiry_date')->values();

        $cols = self::BASE_COLS + ($status === 'expired'
            ? ['days' => 'Days overdue', 'case_status' => 'Renewal', 'last_reminder' => 'Last reminder']
            : ['days' => 'Days left']);

        return [$cols, $rows];
    }

    private function expiring(array $f, CarbonImmutable $today): array
    {
        $days = max(1, min(365, (int) ($f['days'] ?? 30)));
        $rows = $this->credentialQuery($f)->whereIn('current_status', ['active', 'expiring_soon'])
            ->whereHas('currentPeriod', fn ($q) => $q->whereBetween('expiry_date', [$today->toDateString(), $today->addDays($days)->toDateString()]))
            ->get()->map(fn (Credential $c) => $this->base($c, $today) + ['case_status' => $c->openRenewalCase?->status->label() ?? 'None'])
            ->sortBy('expiry_date')->values();

        return [self::BASE_COLS + ['days' => 'Days left', 'case_status' => 'Renewal'], $rows];
    }

    private function pending(array $f, CarbonImmutable $today): array
    {
        $rows = RenewalCase::open()->with(['credential.student.program', 'credential.type', 'reviewer'])
            ->whereHas('credential', fn ($q) => $q->when($f['type'] ?? null, fn ($w, $v) => $w->where('credential_type_id', $v))
                ->whereHas('student', fn ($s) => $s->when($f['program'] ?? null, fn ($w, $v) => $w->where('program_id', $v))
                    ->when($f['cohort'] ?? null, fn ($w, $v) => $w->where('cohort', $v))))
            ->orderBy('submitted_at')->get()
            ->map(fn (RenewalCase $c) => [
                'case' => '#'.$c->id,
                'student_number' => $c->credential->student->student_number,
                'student' => $c->credential->student->fullName(),
                'credential' => $c->credential->type->name,
                'status' => $c->status->label(),
                'opened' => $c->created_at->timezone(Clock::timezone())->format('Y-m-d'),
                'submitted' => $c->submitted_at?->timezone(Clock::timezone())->format('Y-m-d') ?? '',
                'age_days' => $c->submitted_at ? (int) $c->submitted_at->diffInDays(now()) : null,
                'reviewer' => $c->reviewer?->name ?? '',
            ]);

        return [['case' => 'Case', 'student_number' => 'Student no.', 'student' => 'Student', 'credential' => 'Credential',
            'status' => 'Status', 'opened' => 'Opened', 'submitted' => 'Submitted', 'age_days' => 'Days waiting', 'reviewer' => 'Reviewer'], $rows];
    }

    private function rejected(array $f): array
    {
        $rows = Document::with(['student', 'documentType', 'rejectionReason', 'verifier', 'renewalCase.credential.type'])
            ->whereNotNull('rejection_reason_code')
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('verified_at', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('verified_at', '<', CarbonImmutable::parse($v)->addDay()))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->whereHas('renewalCase.credential', fn ($c) => $c->where('credential_type_id', $v)))
            ->orderByDesc('verified_at')->get()
            ->map(fn (Document $d) => [
                'date' => $d->verified_at?->timezone(Clock::timezone())->format('Y-m-d'),
                'student_number' => $d->student->student_number,
                'student' => $d->student->fullName(),
                'credential' => $d->renewalCase?->credential->type->name ?? '',
                'document' => $d->documentType->name,
                'reason' => $d->rejectionReason?->label,
                'note' => $d->rejection_note ?? '',
                'reviewer' => $d->verifier?->name ?? '',
                'resubmitted' => Document::where('case_id', $d->case_id)->where('requirement_id', $d->requirement_id)
                    ->where('version_no', '>', $d->version_no)->exists() ? 'Yes' : 'No',
            ]);

        return [['date' => 'Rejected on', 'student_number' => 'Student no.', 'student' => 'Student', 'credential' => 'Credential',
            'document' => 'Document', 'reason' => 'Reason', 'note' => 'Note', 'reviewer' => 'Reviewer', 'resubmitted' => 'Corrected'], $rows];
    }

    private function compliance(array $f, CarbonImmutable $today): array
    {
        $asOf = isset($f['as_of']) ? CarbonImmutable::parse($f['as_of'], Clock::timezone()) : $today;
        $students = Student::with('program')->monitored()
            ->when($f['program'] ?? null, fn ($q, $v) => $q->where('program_id', $v))
            ->when($f['cohort'] ?? null, fn ($q, $v) => $q->where('cohort', $v))
            ->orderBy('last_name')->get();

        if ($asOf->lt($today)) {
            // Point-in-time answer from the daily snapshots (SRS 16).
            $snaps = ComplianceSnapshot::whereDate('snapshot_date', $asOf->toDateString())
                ->join('credentials', 'credentials.id', '=', 'compliance_snapshots.credential_id')
                ->join('credential_types', 'credential_types.id', '=', 'credentials.credential_type_id')
                ->get(['compliance_snapshots.*', 'credential_types.name as type_name'])->groupBy('student_id');
            $rows = $students->filter(fn ($s) => $snaps->has($s->id))->map(function ($s) use ($snaps) {
                $own = $snaps[$s->id];
                $state = ComplianceState::from($own->first()->compliance_state);

                return $this->complianceRow($s, $state, $own->filter(fn ($x) => $x->validity_status !== 'active')
                    ->map(fn ($x) => $x->type_name.' ('.ValidityStatus::from($x->validity_status)->label().')')->implode('; '));
            });
        } else {
            $students->load('currentCredentials.type', 'program.requiredCredentialTypes');
            $rows = $students->map(function ($s) {
                $held = $s->currentCredentials->keyBy('credential_type_id');
                $issues = $s->currentCredentials->filter(fn ($c) => $c->current_status !== ValidityStatus::Active)
                    ->map(fn ($c) => $c->type->name.' ('.$c->current_status->label().')');
                foreach ($s->program?->requiredCredentialTypes ?? [] as $t) {
                    if (! $held->has($t->id)) {
                        $issues->push($t->name.' (Missing)');
                    }
                }
                if (! $s->program) {
                    $issues->push('No program assigned');
                }

                return $this->complianceRow($s, $s->compliance_state, $issues->implode('; '));
            });
        }
        if (isset($f['state'])) {
            $rows = $rows->filter(fn ($r) => $r['state_code'] === $f['state']);
        }
        $order = ['non_compliant' => 0, 'data_issue' => 1, 'at_risk' => 2, 'compliant' => 3];
        $rows = $rows->sortBy(fn ($r) => $order[$r['state_code']] ?? 9)->values()->map(fn ($r) => Arr::except($r, 'state_code'));

        return [['student_number' => 'Student no.', 'student' => 'Student', 'program' => 'Program', 'cohort' => 'Cohort',
            'state' => 'Compliance', 'issues' => 'Needs attention'], $rows];
    }

    private function complianceRow(Student $s, ComplianceState $state, string $issues): array
    {
        return ['student_number' => $s->student_number, 'student' => $s->fullName(), 'program' => $s->program?->code ?? '',
            'cohort' => $s->cohort ?? '', 'state' => $state->label(), 'issues' => $issues, 'state_code' => $state->value];
    }

    private function history(array $f): array
    {
        $rows = RenewalCase::where('status', RenewalCaseStatus::Completed->value)
            ->with(['credential.student.program', 'credential.type', 'reviewer'])
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('completed_at', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('completed_at', '<', CarbonImmutable::parse($v)->addDay()))
            ->whereHas('credential', fn ($q) => $q->when($f['type'] ?? null, fn ($w, $v) => $w->where('credential_type_id', $v))
                ->whereHas('student', fn ($s) => $s->when($f['program'] ?? null, fn ($w, $v) => $w->where('program_id', $v))))
            ->orderByDesc('completed_at')->get()
            ->map(fn (RenewalCase $c) => [
                'case' => '#'.$c->id,
                'student_number' => $c->credential->student->student_number,
                'student' => $c->credential->student->fullName(),
                'credential' => $c->credential->type->name,
                'submitted' => $c->submitted_at?->timezone(Clock::timezone())->format('Y-m-d') ?? '',
                'completed' => $c->completed_at->timezone(Clock::timezone())->format('Y-m-d'),
                'turnaround' => $c->submitted_at ? (int) $c->submitted_at->diffInDays($c->completed_at) : null,
                'new_expiry' => $c->credential->periods()->where('renewal_case_id', $c->id)->value('expiry_date'),
            ])->map(function ($r) {
                $r['new_expiry'] = $r['new_expiry'] ? substr((string) $r['new_expiry'], 0, 10) : '';

                return $r;
            });

        return [['case' => 'Case', 'student_number' => 'Student no.', 'student' => 'Student', 'credential' => 'Credential',
            'submitted' => 'Submitted', 'completed' => 'Completed', 'turnaround' => 'Turnaround (days)', 'new_expiry' => 'New expiry'], $rows];
    }

    private function notifications(array $f): array
    {
        $rows = OutboundNotification::with(['user', 'student'])
            ->whereIn('status', ['sent', 'failed', 'suppressed', 'cancelled'])
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<', CarbonImmutable::parse($v)->addDay()))
            ->when($f['channel'] ?? null, fn ($q, $v) => $q->where('channel', $v))
            ->when($f['notification_status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->latest('id')->limit(5000)->get()
            ->map(fn (OutboundNotification $n) => [
                'date' => $n->created_at->timezone(Clock::timezone())->format('Y-m-d H:i'),
                'recipient' => $n->user?->name ?? ($n->student?->fullName() ?? ''),
                'address' => $n->channel === 'email' ? ($n->recipientAddress() ?? '') : '',
                'channel' => $n->channel === 'in_app' ? 'In-app' : ucfirst($n->channel),
                'type' => str_replace('_', ' ', $n->event_type),
                'subject' => $n->subject ?? '',
                'status' => ucfirst($n->status),
                'attempts' => $n->attempt_count,
                'error' => $n->last_error ?? '',
            ]);

        return [['date' => 'Created', 'recipient' => 'Recipient', 'address' => 'Email', 'channel' => 'Channel', 'type' => 'Type',
            'subject' => 'Subject', 'status' => 'Status', 'attempts' => 'Attempts', 'error' => 'Problem'], $rows];
    }

    private function dataQuality(array $f): array
    {
        $students = Student::monitored()->with(['program.requiredCredentialTypes', 'currentCredentials.type', 'currentCredentials.currentPeriod', 'user'])
            ->when($f['program'] ?? null, fn ($q, $v) => $q->where('program_id', $v))->orderBy('last_name')->get();
        $rows = collect();
        foreach ($students as $s) {
            $add = fn (string $issue, string $fix) => $rows->push(['student_number' => $s->student_number, 'student' => $s->fullName(), 'issue' => $issue, 'fix' => $fix]);
            if (! $s->program) {
                $add('No program assigned', 'Assign a program so required credentials are known');
            }
            foreach ($s->currentCredentials as $c) {
                if ($c->current_status === ValidityStatus::IncompleteData) {
                    $add($c->type->name.': '.(! $c->currentPeriod?->expiry_date ? 'no expiry date' : 'no license number'), 'Correct the credential details');
                }
            }
            foreach ($s->program?->requiredCredentialTypes ?? [] as $t) {
                if (! $s->currentCredentials->contains('credential_type_id', $t->id)) {
                    $add("Required {$t->name} not recorded", 'Record the credential or confirm it is missing');
                }
            }
            if ($s->email_invalid_at) {
                $add('Email address bounced', 'Update the email address');
            }
            if (! $s->user_id) {
                $add('No account yet', 'Send the account invitation');
            }
        }

        return [['student_number' => 'Student no.', 'student' => 'Student', 'issue' => 'Issue', 'fix' => 'Suggested fix'], $rows];
    }

    private function audit(array $f): array
    {
        $rows = AuditLog::with('actor')
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('occurred_at', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('occurred_at', '<', CarbonImmutable::parse($v)->addDay()))
            ->orderBy('id')->limit(20000)->get()
            ->map(fn (AuditLog $l) => [
                'id' => $l->id,
                'when' => $l->occurred_at->timezone(Clock::timezone())->format('Y-m-d H:i:s'),
                'actor' => AuditPresenter::actor($l),
                'action' => $l->action,
                'record' => $l->entity_type.' #'.$l->entity_id,
                'student_id' => $l->student_id,
                'changes' => implode('; ', AuditPresenter::changes($l)),
                'remarks' => $l->remarks ?? '',
                'ip' => $l->ip_address ?? '',
            ]);

        return [['id' => 'Entry', 'when' => 'When (Manila)', 'actor' => 'Who', 'action' => 'Action', 'record' => 'Record',
            'student_id' => 'Student ID', 'changes' => 'Changes', 'remarks' => 'Remarks', 'ip' => 'IP address'], $rows];
    }
}
