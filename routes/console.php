<?php

use App\Domain\Audit\AuditLogger;
use App\Domain\Clock;
use App\Domain\Compliance\ComplianceEngine;
use App\Domain\Notifications\NotificationService;
use App\Domain\Renewals\RenewalService;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\JobRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/* UC-08 — nightly compliance evaluation (FR-025). Safe to run more than once a day. */
Artisan::command('compliance:evaluate {--date= : Evaluate as of YYYY-MM-DD (testing/catch-up)}', function (ComplianceEngine $engine) {
    $date = $this->option('date') ? CarbonImmutable::parse($this->option('date'), Clock::timezone()) : Clock::today();
    $summary = $engine->runNightly($date);
    Cache::forget('dashboard:counts');
    $this->info("Evaluated {$summary['students']} students and {$summary['credentials']} credentials as of {$date->toDateString()}.");
    foreach ($summary['by_state'] as $state => $n) {
        $this->line("  {$state}: {$n}");
    }
})->purpose('Recalculate credential validity and student compliance, and write today\'s snapshot');

/* SRS 27 — tamper-evidence check of the audit hash chain. */
Artisan::command('audit:verify', function (AuditLogger $audit) {
    $r = $audit->verifyChain();
    if ($r['ok']) {
        $this->info("Audit chain intact ({$r['checked']} entries).");

        return 0;
    }
    $this->error("Audit chain broken at entry #{$r['broken_at']} after {$r['checked']} valid entries.");
    logger()->critical('Audit chain verification failed', $r);

    return 1;
})->purpose('Verify the audit log hash chain');

/* Bootstrap the first Admin accounts (BR-043 asks for two). */
Artisan::command('splms:create-user {email} {name} {--role=admin}', function () {
    $role = Role::from($this->option('role'));
    $password = $this->secret('Password (12+ characters)');
    $v = Validator::make(['password' => $password, 'email' => $this->argument('email')], [
        'email' => ['required', 'email', 'unique:users,email'], 'password' => ['required', Password::defaults()],
    ]);
    if ($v->fails()) {
        foreach ($v->errors()->all() as $e) {
            $this->error($e);
        }

        return 1;
    }
    $user = new User;
    $user->forceFill([
        'name' => $this->argument('name'), 'email' => mb_strtolower($this->argument('email')),
        'password' => $password, 'role' => $role, 'status' => UserStatus::Active, 'password_changed_at' => now(),
    ])->save();
    app(AuditLogger::class)->record('user.created', 'user', $user->id, null, ['role' => ['old' => null, 'new' => $role->value]], 'Created from the command line', null);
    $this->info("{$role->label()} {$user->email} created. Two-step verification is set up at first sign-in.");
})->purpose('Create a user account from the command line');

/* UC-09 — create due reminders and idle-case nudges (Phase 4). */
Artisan::command('notifications:schedule', function (NotificationService $n) {
    $run = JobRun::create(['job_name' => 'notifications:schedule', 'started_at' => now()]);
    $today = Clock::today();
    try {
        $reminders = $n->scheduleReminders($today);
        $idle = $n->scheduleIdleCaseReminders($today);
        $run->update(['finished_at' => now(), 'status' => 'succeeded', 'items_processed' => $reminders + $idle,
            'summary' => ['as_of' => $today->toDateString(), 'reminders' => $reminders, 'idle' => $idle]]);
        $this->info("Queued {$reminders} reminders and {$idle} idle-case nudges.");
    } catch (Throwable $e) {
        $run->update(['finished_at' => now(), 'status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000)]);
        throw $e;
    }
})->purpose('Queue expiry reminders and idle-renewal reminders due today');

/* FR-056 — deliver queued messages, retrying failures with backoff. */
Artisan::command('notifications:send {--limit=200}', function (NotificationService $n) {
    $r = $n->deliverDue((int) $this->option('limit'));
    $this->info("Sent {$r['sent']}, retrying {$r['retrying']}, failed {$r['failed']}, suppressed {$r['suppressed']}.");
})->purpose('Deliver pending notifications');

Artisan::command('notifications:digest', function (NotificationService $n) {
    $this->info('Queued '.$n->sendStaffDigest().' staff digests.');
})->purpose('Queue the weekday staff digest');

/* BR-027 — warn and cancel abandoned drafts. */
Artisan::command('renewals:housekeeping', function (RenewalService $r) {
    $x = $r->housekeeping();
    $this->info("Warned {$x['warned']} drafts, cancelled {$x['cancelled']}.");
})->purpose('Warn about and cancel abandoned renewal drafts');

// NFR-017: schedules run on Manila time.
Schedule::command('compliance:evaluate')->dailyAt('00:05')->timezone(config('splms.timezone'))->withoutOverlapping()->onOneServer();
Schedule::command('audit:verify')->dailyAt('01:00')->timezone(config('splms.timezone'));
Schedule::command('renewals:housekeeping')->dailyAt('05:30')->timezone(config('splms.timezone'));
Schedule::command('notifications:schedule')->dailyAt('06:00')->timezone(config('splms.timezone'))->withoutOverlapping();
Schedule::command('notifications:send')->everyMinute()->withoutOverlapping();
Schedule::command('notifications:digest')->weekdays()->at('08:00')->timezone(config('splms.timezone'));
Schedule::command('queue:work --stop-when-empty --max-time=50')->everyMinute()->withoutOverlapping();
