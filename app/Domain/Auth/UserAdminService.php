<?php

namespace App\Domain\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\DomainRuleViolation;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/** FR-007, BR-043 — Admin management of staff-side accounts. Students are invited from their record (FR-008). */
class UserAdminService
{
    public function __construct(private readonly AuditLogger $audit, private readonly MfaService $mfa) {}

    public function invite(string $name, string $email, Role $role, User $admin): User
    {
        if ($role === Role::Student) {
            throw DomainRuleViolation::on('role', 'Student accounts are created from the student record (FR-008).');
        }
        $email = mb_strtolower(trim($email));
        if (User::whereRaw('lower(email) = ?', [$email])->exists()) {
            throw DomainRuleViolation::on('email', 'BR-002: An account with this email already exists.');
        }

        return DB::transaction(function () use ($name, $email, $role, $admin) {
            $user = new User;
            $user->forceFill(['name' => trim($name), 'email' => $email, 'password' => Str::password(40),
                'role' => $role, 'status' => UserStatus::Invited])->save();
            $user->notify(new InvitationNotification(Password::broker()->createToken($user)));
            $this->audit->record('user.created', 'user', $user->id, null, ['role' => ['old' => null, 'new' => $role->value]], 'Invitation sent', $admin);

            return $user;
        });
    }

    public function changeRole(User $user, Role $role, User $admin, string $reason): void
    {
        $this->guardSelf($user, $admin);
        if ($role === Role::Student || $user->role === Role::Student) {
            throw DomainRuleViolation::on('role', 'Student accounts cannot change role.');
        }
        if ($user->role === Role::Admin && $role !== Role::Admin) {
            $this->guardAdminCount($user);
        }
        DB::transaction(function () use ($user, $role, $admin, $reason) {
            $old = $user->role;
            $user->forceFill(['role' => $role])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();   // new permissions take effect at next sign-in
            $this->audit->record('user.role_changed', 'user', $user->id, null, ['role' => ['old' => $old->value, 'new' => $role->value]], $reason, $admin);
        });
    }

    public function deactivate(User $user, User $admin, string $reason): void
    {
        $this->guardSelf($user, $admin);
        if ($user->role === Role::Admin) {
            $this->guardAdminCount($user);
        }
        DB::transaction(function () use ($user, $admin, $reason) {
            $old = $user->status;
            $user->forceFill(['status' => UserStatus::Deactivated, 'deactivated_at' => now()])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $this->audit->record('user.deactivated', 'user', $user->id, $user->student?->id, ['status' => ['old' => $old->value, 'new' => 'deactivated']], $reason, $admin);
        });
    }

    public function reactivate(User $user, User $admin, string $reason): void
    {
        if ($user->student && ! $user->student->status->isMonitored()) {
            throw DomainRuleViolation::on('user', 'Reactivate the student record first.');
        }
        $user->forceFill(['status' => UserStatus::Active, 'deactivated_at' => null, 'failed_login_count' => 0, 'locked_until' => null])->save();
        $this->audit->record('user.reactivated', 'user', $user->id, $user->student?->id, ['status' => ['old' => 'deactivated', 'new' => 'active']], $reason, $admin);
    }

    public function unlock(User $user, User $admin): void
    {
        $user->forceFill(['status' => UserStatus::Active, 'failed_login_count' => 0, 'locked_until' => null])->save();
        $this->audit->record('user.unlocked', 'user', $user->id, $user->student?->id, null, null, $admin);
    }

    public function resetMfa(User $user, User $admin, string $reason): void
    {
        $this->guardSelf($user, $admin);
        $this->mfa->reset($user, $admin, $reason);
        DB::table('sessions')->where('user_id', $user->id)->delete();
    }

    private function guardSelf(User $user, User $admin): void
    {
        if ($user->id === $admin->id) {
            throw DomainRuleViolation::on('user', 'You cannot change your own account here. Ask another Admin.');
        }
    }

    /** BR-043: never drop below the minimum number of active Admins. */
    private function guardAdminCount(User $leaving): void
    {
        $active = User::where('role', Role::Admin->value)->where('status', UserStatus::Active->value)->count();
        $min = config('splms.min_active_admins');
        if ($active <= $min) {
            throw DomainRuleViolation::on('user', "BR-043: At least {$min} active Admin accounts must remain. Add another Admin first.");
        }
    }
}
