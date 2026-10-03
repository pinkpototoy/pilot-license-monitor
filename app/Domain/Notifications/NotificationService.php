<?php

namespace App\Domain\Notifications;

use App\Domain\Clock;
use App\Domain\Compliance\ValidityCalculator;
use App\Enums\RenewalCaseStatus;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Credential;
use App\Models\JobRun;
use App\Models\NotificationRule;
use App\Models\OutboundNotification;
use App\Models\RenewalCase;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SRS 15 — rule-driven reminders and event notifications.
 * Every message is stored before it is sent (nothing is lost if mail is down) and carries a
 * unique dedupe key, so re-running any job never sends twice (BR-030, FR-055, AC-07).
 */
class NotificationService
{
    /** Categories a student cannot switch off by email (SRS 15.2). */
    public const MANDATORY_EMAIL = ['expiry_reminder'];

    public function __construct(private readonly TemplateRenderer $renderer) {}

    // ------------------------------------------------------------------ scheduling

    /**
     * UC-09 — create reminders for every rule whose date has been reached.
     * Catch-up (SRS 15.2): if days were missed, only the most recent missed offset per credential is sent.
     *
     * @return int notifications created
     */
    public function scheduleReminders(?CarbonImmutable $today = null): int
    {
        $today ??= Clock::today();
        $lastRun = JobRun::where('job_name', 'notifications:schedule')->where('status', 'succeeded')
            ->latest('started_at')->value('summary');
        $lastDate = isset($lastRun['as_of']) ? CarbonImmutable::parse($lastRun['as_of'], Clock::timezone()) : null;
        // Window of due dates covered by this run: (lastDate, today], at most 31 days back.
        $from = $lastDate && $lastDate->lt($today) ? $lastDate->addDay()->max($today->subDays(31)) : $today;

        $rules = NotificationRule::where('active', true)->get();
        if ($rules->isEmpty()) {
            return 0;
        }
        $staff = $this->staffRecipients();
        $created = 0;

        Credential::query()->whereNull('archived_at')
            ->whereHas('student', fn ($q) => $q->monitored())
            ->whereHas('type', fn ($q) => $q->where('expires', true))
            ->with(['student.user', 'type', 'currentPeriod', 'openRenewalCase'])
            ->chunkById(200, function ($credentials) use ($rules, $today, $from, $staff, &$created) {
                foreach ($credentials as $credential) {
                    $period = $credential->currentPeriod;
                    if (! $period?->expiry_date) {
                        continue;   // EC-01: Incomplete Data gets no reminders until fixed
                    }
                    $expiry = CarbonImmutable::parse($period->expiry_date->format('Y-m-d'), Clock::timezone());

                    $due = $rules
                        ->filter(fn ($r) => $r->credential_type_id === null || $r->credential_type_id === $credential->credential_type_id)
                        ->map(fn ($r) => ['rule' => $r, 'date' => $expiry->addDays($r->offset_days)])
                        ->filter(fn ($x) => $x['date']->betweenIncluded($from, $today))
                        ->sortByDesc(fn ($x) => $x['date']->timestamp);
                    $pick = $due->first();
                    if (! $pick) {
                        continue;
                    }
                    $rule = $pick['rule'];

                    // FR-057 / BR-034: pause while a renewal is open, except the expiry-day notice.
                    if ($credential->openRenewalCase && $rule->offset_days !== 0) {
                        continue;
                    }
                    $created += $this->createReminder($credential, $rule, $today, $staff);
                }
            });

        return $created;
    }

    private function createReminder(Credential $credential, NotificationRule $rule, CarbonImmutable $today, Collection $staff): int
    {
        $student = $credential->student;
        $period = $credential->currentPeriod;
        $days = ValidityCalculator::daysRemaining($period->expiry_date, $today);
        $vars = $this->credentialVars($credential, $today) + [
            'action_line' => $credential->openRenewalCase
                ? 'Your renewal is in progress ('.$credential->openRenewalCase->status->label().'). No action is needed unless the records office asks for something.'
                : ($days >= 0
                    ? 'Renew it with the issuing authority, then sign in and upload the renewed document so the records office can verify it.'
                    : 'It has expired. Do not fly or train on it until it is renewed, then upload the renewed document.'),
        ];
        $created = 0;

        if (in_array($rule->recipients, ['student', 'both'], true)) {
            foreach ($rule->channels as $channel) {
                $created += $this->queueFor($student, $channel, 'expiry_reminder', $vars,
                    "rule:{$rule->id}:period:{$period->id}", ['rule_id' => $rule->id, 'period_id' => $period->id]);
            }
        }
        if (in_array($rule->recipients, ['staff', 'both'], true)) {
            $staffVars = $vars + ['action_line' => "{$student->fullName()} ({$student->student_number}): {$credential->type->name} expires {$vars['expiry_date']} ({$vars['days_text']})."];
            foreach ($staff as $user) {
                $created += $this->queueForUser($user, 'in_app', 'expiry_reminder_staff', $staffVars,
                    "rule:{$rule->id}:period:{$period->id}", ['rule_id' => $rule->id, 'period_id' => $period->id, 'student_id' => $student->id]);
            }
        }

        return $created;
    }

    /**
     * FR-052 — event notifications (renewal submitted, decisions, completion, idle reminders).
     *
     * @param  list<string>  $audience  'student' and/or 'staff'
     */
    public function event(string $code, Student $student, array $vars, array $audience, string $dedupe, ?RenewalCase $case = null): int
    {
        $vars += $this->studentVars($student);
        $extra = ['case_id' => $case?->id];
        $n = 0;
        if (in_array('student', $audience, true)) {
            foreach (['email', 'in_app'] as $channel) {
                $n += $this->queueFor($student, $channel, $code, $vars, $dedupe, $extra);
            }
        }
        if (in_array('staff', $audience, true)) {
            foreach ($this->staffRecipients() as $user) {
                $n += $this->queueForUser($user, 'in_app', $code.'_staff', $vars, $dedupe, $extra + ['student_id' => $student->id]);
            }
        }

        return $n;
    }

    /** BR-032 / EC-09 — dates changed: pending reminders for this credential are cancelled; rules re-run against the new period. */
    public function cancelPendingForCredential(Credential $credential): int
    {
        $periodIds = $credential->periods()->pluck('id');

        return OutboundNotification::whereIn('period_id', $periodIds)->where('status', 'pending')
            ->update(['status' => 'cancelled', 'last_error' => 'Dates changed (BR-032)', 'updated_at' => now()]);
    }

    /** EC-12 / BR-004 — nothing more goes to a deactivated student. */
    public function cancelPendingForStudent(Student $student): int
    {
        return OutboundNotification::where('status', 'pending')
            ->where(fn ($q) => $q->where('student_id', $student->id)
                ->when($student->user_id, fn ($w) => $w->orWhere('user_id', $student->user_id)))
            ->update(['status' => 'cancelled', 'last_error' => 'Student deactivated (BR-004)', 'updated_at' => now()]);
    }

    /** SRS 15.1 — idle Draft and Needs Correction cases get one nudge per idle stretch. */
    public function scheduleIdleCaseReminders(?CarbonImmutable $today = null): int
    {
        $today ??= Clock::today();
        $cfg = config('splms.renewals');
        $n = 0;
        $idle = [
            [RenewalCaseStatus::Draft, $cfg['draft_idle_reminder_days'], 'draft_idle'],
            [RenewalCaseStatus::NeedsCorrection, $cfg['correction_idle_reminder_days'], 'correction_idle'],
        ];
        foreach ($idle as [$status, $days, $code]) {
            RenewalCase::where('status', $status->value)
                ->where('last_activity_at', '<=', $today->subDays($days)->endOfDay())
                ->with('credential.student', 'credential.type', 'credential.currentPeriod')->get()
                ->each(function (RenewalCase $case) use ($code, $today, &$n) {
                    $student = $case->credential->student;
                    if (! $student->status->isMonitored()) {
                        return;
                    }
                    $n += $this->event($code, $student, $this->credentialVars($case->credential, $today) + [
                        'case_status' => $case->status->label(),
                    ], ['student'], "case:{$case->id}:idle:".$case->last_activity_at->format('Ymd'), $case);
                });
        }

        return $n;
    }

    /** SRS 15.1 — weekday staff digest by email. */
    public function sendStaffDigest(?CarbonImmutable $today = null): int
    {
        $today ??= Clock::today();
        $base = fn () => Credential::query()->whereNull('archived_at')
            ->whereHas('student', fn ($q) => $q->monitored())->whereDoesntHave('openRenewalCase');
        $soon = $base()->where('current_status', 'expiring_soon')
            ->whereHas('currentPeriod', fn ($q) => $q->where('expiry_date', '<=', $today->addDays(14)->toDateString()))->count();
        $expired = $base()->where('current_status', 'expired')->count();
        $queue = RenewalCase::whereIn('status', ['submitted', 'resubmitted', 'under_verification'])->get(['submitted_at']);
        $oldest = $queue->min('submitted_at');
        $failed = OutboundNotification::where('status', 'failed')->where('updated_at', '>=', now()->subDays(7))->count();

        $lines = [
            "Expiring within 14 days, no renewal started: {$soon}",
            "Expired, no renewal started: {$expired}",
            'Waiting for verification: '.$queue->count().($oldest ? ' (oldest submitted '.$oldest->timezone(Clock::timezone())->format('d M Y').')' : ''),
            "Notifications that failed to deliver (7 days): {$failed}",
        ];
        $vars = ['action_line' => implode("\n", $lines), 'digest_date' => $today->format('d M Y')];
        $n = 0;
        foreach ($this->staffRecipients() as $user) {
            $n += $this->queueForUser($user, 'email', 'staff_digest', $vars, 'digest:'.$today->toDateString(), []);
        }

        return $n;
    }

    // ------------------------------------------------------------------ delivery

    /** FR-056 — send what is due; retry with backoff; after the final failure flag it for staff. */
    public function deliverDue(int $limit = 200): array
    {
        $result = ['sent' => 0, 'retrying' => 0, 'failed' => 0, 'suppressed' => 0];
        $due = OutboundNotification::with(['user', 'student'])
            ->where('status', 'pending')
            ->where('scheduled_for', '<=', now())
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('scheduled_for')->limit($limit)->get();

        foreach ($due as $n) {
            if ($reason = $this->suppressionReason($n)) {
                $n->update(['status' => 'suppressed', 'last_error' => $reason]);
                $result['suppressed']++;

                continue;
            }
            $sender = $this->sender($n->channel);
            $attemptNo = $n->attempt_count + 1;
            try {
                $messageId = $sender->send($n);
                DB::transaction(function () use ($n, $attemptNo, $sender, $messageId) {
                    $n->attempts()->create(['attempt_no' => $attemptNo, 'provider' => $sender->name(),
                        'provider_message_id' => $messageId, 'result' => 'sent', 'attempted_at' => now()]);
                    $n->update(['status' => 'sent', 'sent_at' => now(), 'attempt_count' => $attemptNo, 'last_error' => null]);
                });
                $result['sent']++;
            } catch (Throwable $e) {
                $final = $attemptNo >= config('splms.notifications.max_attempts');
                $delays = config('splms.notifications.retry_minutes');
                DB::transaction(function () use ($n, $attemptNo, $sender, $e, $final, $delays) {
                    $n->attempts()->create(['attempt_no' => $attemptNo, 'provider' => $sender->name(),
                        'result' => 'failed', 'error_message' => mb_substr($e->getMessage(), 0, 1000), 'attempted_at' => now()]);
                    $n->update([
                        'status' => $final ? 'failed' : 'pending',
                        'attempt_count' => $attemptNo,
                        'last_error' => mb_substr($e->getMessage(), 0, 1000),
                        'next_attempt_at' => $final ? null : now()->addMinutes($delays[$attemptNo - 1] ?? end($delays)),
                    ]);
                });
                if ($final) {
                    $this->alertDeliveryFailure($n);
                    $result['failed']++;
                } else {
                    $result['retrying']++;
                }
            }
        }

        return $result;
    }

    private function suppressionReason(OutboundNotification $n): ?string
    {
        if ($n->student && ! $n->student->status->isMonitored() && $n->user?->role !== Role::Staff && $n->user?->role !== Role::Admin) {
            return 'Student is not active (BR-033)';
        }
        if ($n->channel === 'email') {
            if ($n->user_id === null && $n->student?->email_invalid_at) {
                return 'Email address marked invalid (BR-033)';
            }
            if ($n->user?->email_invalid_at) {
                return 'Email address marked invalid (BR-033)';
            }
        }
        if ($n->channel === 'sms' && ! config('splms.notifications.sms_enabled')) {
            return 'SMS is disabled';
        }

        return null;
    }

    private function alertDeliveryFailure(OutboundNotification $n): void
    {
        $student = $n->student;
        $vars = ['action_line' => 'A '.$n->channel.' message "'.($n->subject ?? $n->event_type).'" to '
            .($n->recipientAddress() ?? 'a user').' failed after '.$n->attempt_count.' attempts: '.$n->last_error
            .($student ? ". Contact {$student->fullName()} ({$student->student_number}) another way." : '.')];
        foreach ($this->staffRecipients() as $user) {
            $this->queueForUser($user, 'in_app', 'delivery_failure', $vars, "failure:{$n->id}", ['student_id' => $student?->id]);
        }
    }

    public function sender(string $channel): ChannelSender
    {
        return match ($channel) {
            'email' => app(EmailSender::class),
            'in_app' => app(InAppSender::class),
            'sms' => app(SmsSender::class),
        };
    }

    // ------------------------------------------------------------------ helpers

    /** Student recipient: email goes to the student record's address even before an account exists. */
    private function queueFor(Student $student, string $channel, string $code, array $vars, string $dedupe, array $extra): int
    {
        if ($channel === 'sms' && ! config('splms.notifications.sms_enabled')) {
            return 0;
        }
        $user = $student->user && $student->user->status !== UserStatus::Deactivated ? $student->user : null;
        if ($channel === 'in_app' && ! $user) {
            return 0;   // no inbox without an account
        }
        if ($user && ! $this->allowed($user, $code, $channel)) {
            return 0;
        }

        return $this->insert([
            'user_id' => $user?->id,
            'recipient_email' => $channel === 'email' && ! $user ? $student->email : null,
            'student_id' => $student->id,
        ] + $extra, $channel, $code, $vars, $dedupe.':student:'.$student->id);
    }

    private function queueForUser(User $user, string $channel, string $code, array $vars, string $dedupe, array $extra): int
    {
        if (! $this->allowed($user, $code, $channel)) {
            return 0;
        }

        return $this->insert(['user_id' => $user->id] + $extra, $channel, $code, $vars, $dedupe.':user:'.$user->id);
    }

    private function insert(array $who, string $channel, string $code, array $vars, string $dedupe): int
    {
        $msg = $this->renderer->render($code, $channel, $vars);
        $key = hash('sha256', $dedupe.':'.$channel.':'.$code);

        // Unique index on dedupe_key: a repeat insert is ignored, not an error.
        return DB::table('notifications')->insertOrIgnore([
            'user_id' => $who['user_id'] ?? null,
            'recipient_email' => $who['recipient_email'] ?? null,
            'student_id' => $who['student_id'] ?? null,
            'rule_id' => $who['rule_id'] ?? null,
            'period_id' => $who['period_id'] ?? null,
            'case_id' => $who['case_id'] ?? null,
            'event_type' => $code,
            'channel' => $channel,
            'dedupe_key' => $key,
            'subject' => mb_substr($msg['subject'], 0, 255),
            'body_rendered' => $msg['body'],
            'status' => 'pending',
            'scheduled_for' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function allowed(User $user, string $code, string $channel): bool
    {
        if ($channel === 'email' && in_array($code, self::MANDATORY_EMAIL, true)) {
            return true;
        }
        $pref = DB::table('notification_preferences')->where('user_id', $user->id)
            ->where('event_type', $code)->where('channel', $channel)->value('enabled');

        return $pref === null || (bool) $pref;
    }

    /** @return Collection<int, User> */
    private function staffRecipients(): Collection
    {
        return User::whereIn('role', [Role::Staff->value, Role::Admin->value])
            ->where('status', UserStatus::Active->value)->get();
    }

    private function studentVars(Student $student): array
    {
        return [
            'student_first_name' => $student->first_name,
            'student_name' => $student->fullName(),
            'student_number' => $student->student_number,
        ];
    }

    private function credentialVars(Credential $credential, CarbonImmutable $today): array
    {
        $expiry = $credential->currentPeriod?->expiry_date;
        $days = $expiry ? ValidityCalculator::daysRemaining($expiry, $today) : null;

        return $this->studentVars($credential->student) + [
            'credential_name' => $credential->type->name,
            'license_number' => $credential->license_number ?? 'not recorded',
            'expiry_date' => $expiry?->format('d M Y') ?? 'not recorded',
            'days_text' => match (true) {
                $days === null => 'date unknown',
                $days > 0 => "in {$days} ".($days === 1 ? 'day' : 'days'),
                $days === 0 => 'today',
                default => abs($days).' '.(abs($days) === 1 ? 'day' : 'days').' ago',
            },
        ];
    }

    public function vars(Credential $credential): array
    {
        return $this->credentialVars($credential, Clock::today());
    }
}
