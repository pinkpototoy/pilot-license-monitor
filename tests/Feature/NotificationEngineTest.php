<?php

namespace Tests\Feature;

use App\Domain\Notifications\EmailSender;
use App\Domain\Notifications\NotificationService;
use App\Domain\Records\CredentialService;
use App\Domain\Renewals\RenewalService;
use App\Enums\Role;
use App\Models\JobRun;
use App\Models\NotificationRule;
use App\Models\OutboundNotification;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class NotificationEngineTest extends TestCase
{
    use RefreshDatabase;

    private NotificationService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        $this->freezeToday('2026-10-03');
        $this->svc = app(NotificationService::class);
    }

    private function studentExpiringIn(int $days, array $o = []): Student
    {
        $s = $this->student($o);
        $this->credential($s, 'SPL', 'SPL-'.$s->id, '2025-01-01', now('Asia/Manila')->addDays($days)->toDateString());

        return $s;
    }

    private function emailsTo(Student $s, string $type = 'expiry_reminder')
    {
        return OutboundNotification::where('student_id', $s->id)->where('event_type', $type)->where('channel', 'email');
    }

    public function test_reminder_on_each_default_offset_and_not_between(): void
    {
        $on30 = $this->studentExpiringIn(30);
        $on29 = $this->studentExpiringIn(29);
        $on0 = $this->studentExpiringIn(0);

        $this->svc->scheduleReminders();
        $this->assertSame(1, $this->emailsTo($on30)->count());
        $this->assertSame(0, $this->emailsTo($on29)->count());
        $this->assertSame(1, $this->emailsTo($on0)->count());
        $this->assertStringContainsString('expires in 30 days', $this->emailsTo($on30)->first()->subject);
    }

    public function test_ac07_running_twice_sends_once(): void
    {
        $s = $this->studentExpiringIn(7);
        $staff = User::whereIn('role', ['admin', 'staff'])->count();
        $this->svc->scheduleReminders();
        $this->svc->scheduleReminders();
        $this->assertSame(1, $this->emailsTo($s)->count());
        // The 7-day rule also alerts every active staff member in-app (recipients = both), once each.
        $this->assertSame($staff, OutboundNotification::where('event_type', 'expiry_reminder_staff')->count());
    }

    public function test_student_without_account_still_gets_email_but_no_inapp(): void
    {
        $s = $this->studentExpiringIn(14);
        $this->svc->scheduleReminders();
        $n = $this->emailsTo($s)->first();
        $this->assertNull($n->user_id);
        $this->assertSame($s->email, $n->recipient_email);
        $this->assertSame(0, OutboundNotification::where('student_id', $s->id)->where('channel', 'in_app')->count());
    }

    public function test_rules_are_configurable_ac06(): void
    {
        $s = $this->studentExpiringIn(45);
        $this->svc->scheduleReminders();
        $this->assertSame(0, $this->emailsTo($s)->count());

        NotificationRule::create(['offset_days' => -45, 'recipients' => 'student', 'channels' => ['email'], 'template_code' => 'expiry_reminder', 'active' => true]);
        $this->svc->scheduleReminders();
        $this->assertSame(1, $this->emailsTo($s)->count());
    }

    public function test_catch_up_sends_only_the_most_recent_missed_offset(): void
    {
        $s = $this->studentExpiringIn(25);   // the 30-day reminder was due 5 days ago
        JobRun::create(['job_name' => 'notifications:schedule', 'status' => 'succeeded', 'started_at' => now()->subDays(6),
            'summary' => ['as_of' => '2026-09-27']]);
        $this->svc->scheduleReminders();
        $this->assertSame(1, $this->emailsTo($s)->count());
        $this->assertSame(NotificationRule::where('offset_days', -30)->value('id'), $this->emailsTo($s)->first()->rule_id);
    }

    public function test_br034_paused_during_open_renewal_except_expiry_day(): void
    {
        $s = $this->studentExpiringIn(7);
        $u = $this->user(Role::Student);
        $s->forceFill(['user_id' => $u->id])->save();
        app(RenewalService::class)->open($s->credentials()->first(), $u);
        $this->svc->scheduleReminders();
        $this->assertSame(0, $this->emailsTo($s)->count());

        $this->freezeToday('2026-10-10');   // expiry day
        $this->svc->scheduleReminders();
        $this->assertSame(1, $this->emailsTo($s)->count());
    }

    public function test_br032_changing_dates_cancels_pending_reminders(): void
    {
        $s = $this->studentExpiringIn(14);
        $this->svc->scheduleReminders();
        $c = $s->credentials()->first();
        app(CredentialService::class)->correctCurrentPeriod($c, $c->license_number, '2025-01-01', '2027-06-01', 'Typo', $this->user());
        $this->assertSame('cancelled', $this->emailsTo($s)->first()->status);
    }

    public function test_delivery_sends_email_and_records_attempt(): void
    {
        Mail::fake();
        $s = $this->studentExpiringIn(30);
        $this->svc->scheduleReminders();
        $r = $this->svc->deliverDue();
        $this->assertSame(1, $r['sent']);
        $n = $this->emailsTo($s)->first();
        $this->assertSame('sent', $n->status);
        $this->assertSame(1, $n->attempts()->count());
    }

    public function test_ac08_failures_retry_with_backoff_then_flag_staff(): void
    {
        $this->user(Role::Staff);
        $this->app->bind(EmailSender::class, fn () => new class extends EmailSender
        {
            public function send($n): ?string
            {
                throw new RuntimeException('SMTP 421 try later');
            }
        });
        $s = $this->studentExpiringIn(30);
        $this->svc->scheduleReminders();

        $this->assertSame(1, $this->svc->deliverDue()['retrying']);
        $n = $this->emailsTo($s)->first();
        $this->assertSame('pending', $n->status);
        $this->assertSame(1, $n->attempt_count);
        $this->assertEqualsWithDelta(5, now()->diffInMinutes($n->next_attempt_at), 1);
        $this->assertSame(0, $this->svc->deliverDue()['retrying'], 'Not retried before its next attempt time');

        foreach ([6, 31, 121, 721] as $minutes) {
            $this->travel($minutes)->minutes();
            $this->svc->deliverDue();
        }
        $n->refresh();
        $this->assertSame('failed', $n->status);
        $this->assertSame(5, $n->attempts()->count());
        $this->assertTrue(OutboundNotification::where('event_type', 'delivery_failure')->where('channel', 'in_app')->exists());
    }

    public function test_br033_invalid_email_is_suppressed(): void
    {
        $s = $this->studentExpiringIn(30);
        $s->forceFill(['email_invalid_at' => now()])->save();
        $this->svc->scheduleReminders();
        $this->assertSame(1, $this->svc->deliverDue()['suppressed']);
    }

    public function test_optional_categories_respect_preferences_mandatory_ones_do_not(): void
    {
        $s = $this->studentExpiringIn(30);
        $u = $this->user(Role::Student);
        $s->forceFill(['user_id' => $u->id])->save();
        foreach (['email', 'in_app'] as $ch) {
            \DB::table('notification_preferences')->insert(['user_id' => $u->id, 'event_type' => 'expiry_reminder', 'channel' => $ch, 'enabled' => false]);
        }
        $this->svc->scheduleReminders();
        $this->assertSame(1, $this->emailsTo($s)->count(), 'Expiry email is mandatory');
        $this->assertSame(0, OutboundNotification::where('student_id', $s->id)->where('channel', 'in_app')->count());
    }

    public function test_staff_digest_and_idle_reminders(): void
    {
        $this->studentExpiringIn(5);
        $staff = User::whereIn('role', ['admin', 'staff'])->count();
        $this->assertSame($staff, $this->svc->sendStaffDigest());
        $this->assertSame(0, $this->svc->sendStaffDigest(), 'One digest per day');
        $body = OutboundNotification::where('event_type', 'staff_digest')->value('body_rendered');
        $this->assertStringContainsString('Expiring within 14 days, no renewal started: 1', $body);

        $s = $this->studentExpiringIn(10);
        $u = $this->user(Role::Student);
        $s->forceFill(['user_id' => $u->id])->save();
        app(RenewalService::class)->open($s->credentials()->first(), $u);
        $this->freezeToday('2026-10-11');
        $this->assertGreaterThan(0, $this->svc->scheduleIdleCaseReminders());
        $this->assertSame(0, $this->svc->scheduleIdleCaseReminders());
    }

    public function test_commands_run(): void
    {
        $this->studentExpiringIn(30);
        $this->artisan('notifications:schedule')->assertSuccessful();
        $this->artisan('notifications:send')->assertSuccessful();
        $this->artisan('renewals:housekeeping')->assertSuccessful();
        $this->assertSame('2026-10-03', JobRun::where('job_name', 'notifications:schedule')->latest('id')->value('summary')['as_of']);
    }
}
