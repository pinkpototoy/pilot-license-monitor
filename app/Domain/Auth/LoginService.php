<?php

namespace App\Domain\Auth;

use App\Domain\Audit\AuditLogger;
use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\AccountLockedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/** FR-001, FR-005 — password step of login, with lockout and attempt logging. */
class LoginService
{
    /** One message for every failure, so attackers learn nothing (SRS 26.1). */
    public const GENERIC_ERROR = 'The email or password is incorrect, or the account cannot sign in.';

    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws LoginFailed */
    public function attempt(string $email, string $password, ?string $ip): User
    {
        $email = mb_strtolower(trim($email));

        // Per-IP limit (SRS 25): 20 attempts per minute.
        $ipKey = 'login-ip:'.$ip;
        if (RateLimiter::tooManyAttempts($ipKey, 20)) {
            $this->log($email, null, $ip, false, 'ip_rate_limited');
            throw new LoginFailed('Too many sign-in attempts from this network. Try again in '.RateLimiter::availableIn($ipKey).' seconds.');
        }
        RateLimiter::hit($ipKey, 60);

        $user = User::whereRaw('lower(email) = ?', [$email])->first();

        if (! $user) {
            Hash::check($password, '$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHQ$AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'); // equalize timing
            $this->log($email, null, $ip, false, 'unknown_email');
            throw new LoginFailed(self::GENERIC_ERROR);
        }

        if ($user->isLocked()) {
            $this->log($email, $user->id, $ip, false, 'locked');
            $minutes = max(1, (int) ceil(now()->diffInSeconds($user->locked_until) / 60));
            throw new LoginFailed("This account is locked after too many failed attempts. Try again in {$minutes} minute(s) or reset your password.");
        }

        if (! Hash::check($password, $user->password)) {
            $this->registerFailure($user, $ip);
            throw new LoginFailed(self::GENERIC_ERROR);
        }

        if (! in_array($user->status, [UserStatus::Active, UserStatus::Locked], true)) {
            $this->log($email, $user->id, $ip, false, 'status_'.$user->status->value);
            throw new LoginFailed(self::GENERIC_ERROR);
        }

        if (Hash::needsRehash($user->password)) {
            $user->password = $password; // re-hashed by the cast
        }
        $user->forceFill([
            'failed_login_count' => 0,
            'locked_until' => null,
            'status' => UserStatus::Active,
        ])->save();
        $this->log($email, $user->id, $ip, true, null);

        return $user;
    }

    private function registerFailure(User $user, ?string $ip): void
    {
        $max = config('splms.lockout.max_attempts');
        DB::transaction(function () use ($user, $ip, $max) {
            $user->refresh();
            $count = $user->failed_login_count + 1;
            $locked = $count >= $max;
            $user->forceFill([
                'failed_login_count' => $locked ? 0 : $count,
                'locked_until' => $locked ? now()->addMinutes(config('splms.lockout.minutes')) : $user->locked_until,
                'status' => $locked ? UserStatus::Locked : $user->status,
            ])->save();
            $this->log($user->email, $user->id, $ip, false, 'bad_password');

            if ($locked) {
                $this->audit->record('auth.account_locked', 'user', $user->id, $user->student?->id, null,
                    "Locked for {$this->minutes()} minutes after {$max} failed sign-in attempts", actor: null);
                $user->notify(new AccountLockedNotification($this->minutes()));
            }
        });
    }

    private function minutes(): int
    {
        return (int) config('splms.lockout.minutes');
    }

    private function log(string $email, ?int $userId, ?string $ip, bool $success, ?string $reason): void
    {
        DB::table('login_attempts')->insert([
            'email_attempted' => mb_substr($email, 0, 255),
            'user_id' => $userId,
            'ip_address' => $ip,
            'success' => $success,
            'failure_reason' => $reason,
            'created_at' => now(),
        ]);
    }
}
