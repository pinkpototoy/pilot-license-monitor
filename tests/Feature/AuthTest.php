<?php

namespace Tests\Feature;

use App\Domain\Auth\LoginService;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\AccountLockedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $email, string $password = 'correct-horse-battery')
    {
        return $this->post('/login', ['email' => $email, 'password' => $password]);
    }

    public function test_ac01_staff_must_enrol_mfa_before_any_page(): void
    {
        $staff = $this->user(Role::Staff);
        $this->login($staff->email)->assertRedirect();
        $this->get('/dashboard')->assertRedirect(route('mfa.enroll'));
        $this->get('/students')->assertRedirect(route('mfa.enroll'));
    }

    public function test_mfa_enrolment_then_challenge_and_replay_protection(): void
    {
        $g = new Google2FA;
        $admin = $this->user(Role::Admin);
        $this->login($admin->email);
        $this->get('/mfa/enroll')->assertOk();
        $secret = session('mfa_pending_secret');

        $this->post('/mfa/enroll', ['code' => '000000'])->assertSessionHasErrors('code');
        $code = $g->getCurrentOtp($secret);
        $this->post('/mfa/enroll', ['code' => $code])->assertRedirect(route('mfa.recovery-codes'));
        $this->assertTrue($admin->refresh()->mfa_enabled);
        $this->get('/dashboard')->assertOk();

        // Next sign-in: challenge. The same code cannot be replayed within its time step.
        $this->post('/logout');
        $this->login($admin->email);
        $this->get('/dashboard')->assertRedirect(route('mfa.challenge'));
        // Replaying the exact code already used is refused, even inside its time window.
        $this->post('/mfa/challenge', ['code' => $code])->assertSessionHasErrors('code');
    }

    public function test_recovery_code_works_once(): void
    {
        $g = new Google2FA;
        $admin = $this->user(Role::Admin);
        $this->login($admin->email);
        $this->get('/mfa/enroll');
        $secret = session('mfa_pending_secret');
        $this->post('/mfa/enroll', ['code' => $g->getCurrentOtp($secret)]);
        $code = session('recovery_codes')[0];

        $this->post('/logout');
        $this->login($admin->email);
        $this->post('/mfa/challenge', ['code' => $code])->assertRedirect();
        $this->get('/dashboard')->assertOk();

        $this->post('/logout');
        $this->login($admin->email);
        $this->post('/mfa/challenge', ['code' => $code])->assertSessionHasErrors('code');
    }

    public function test_ac02_five_failures_lock_for_fifteen_minutes_and_email_owner(): void
    {
        Notification::fake();
        $u = $this->user(Role::Student);
        for ($i = 0; $i < 5; $i++) {
            $this->login($u->email, 'wrong-password')->assertSessionHasErrors('email');
        }
        $u->refresh();
        $this->assertSame(UserStatus::Locked, $u->status);
        $this->assertEqualsWithDelta(15, now()->diffInMinutes($u->locked_until), 1);
        Notification::assertSentTo($u, AccountLockedNotification::class);

        // Even the right password is refused while locked.
        $this->login($u->email)->assertSessionHasErrors('email');
        $this->assertGuest();

        // After 15 minutes the right password works again.
        $this->travel(16)->minutes();
        $this->login($u->email);
        $this->assertAuthenticatedAs($u);
    }

    public function test_generic_error_for_unknown_email_and_wrong_password(): void
    {
        $u = $this->user();
        $generic = LoginService::GENERIC_ERROR;
        $this->login('nobody@example.test')->assertSessionHasErrors(['email' => $generic]);
        $this->login($u->email, 'nope')->assertSessionHasErrors(['email' => $generic]);
        $this->assertDatabaseCount('login_attempts', 2);
    }

    public function test_deactivated_and_invited_users_cannot_sign_in(): void
    {
        $d = User::factory()->create(['status' => UserStatus::Deactivated, 'role' => Role::Staff]);
        $i = User::factory()->create(['status' => UserStatus::Invited, 'role' => Role::Student]);
        $this->login($d->email)->assertSessionHasErrors('email');
        $this->login($i->email)->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_fr006_idle_timeout_by_role(): void
    {
        $student = $this->user(Role::Student);
        $this->actingAs($student)->withSession(['mfa_passed' => true, 'last_seen_at' => now()->subMinutes(31)->getTimestamp(), 'session_started_at' => now()->subHour()->getTimestamp()])
            ->get('/my')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_fr006_absolute_session_lifetime(): void
    {
        $staff = $this->user(Role::Staff);
        $this->actingAs($staff)->withSession(['mfa_passed' => true, 'last_seen_at' => now()->getTimestamp(), 'session_started_at' => now()->subHours(13)->getTimestamp()])
            ->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_security_headers_present(): void
    {
        $r = $this->get('/login');
        $r->assertHeader('X-Frame-Options', 'DENY');
        $r->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString("frame-ancestors 'none'", $r->headers->get('Content-Security-Policy'));
        $this->assertNotEmpty($r->headers->get('X-Request-Id'));
    }
}
