<?php

namespace App\Domain\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\DomainRuleViolation;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Student;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/** FR-008 — student accounts are created by invitation from a student record. */
class InvitationService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function inviteStudent(Student $student, User $actor): User
    {
        if (! $student->status->isMonitored()) {
            throw DomainRuleViolation::on('student', 'Only active students can be invited.');
        }
        if ($student->user && $student->user->status !== UserStatus::Invited) {
            throw DomainRuleViolation::on('student', 'This student already has an account.');
        }
        if (! $student->user && User::whereRaw('lower(email) = ?', [mb_strtolower($student->email)])->exists()) {
            throw DomainRuleViolation::on('email', 'BR-002: Another account already uses this email address.');
        }

        return DB::transaction(function () use ($student, $actor) {
            $user = $student->user ?? tap(new User, function (User $u) use ($student) {
                $u->forceFill([
                    'name' => $student->fullName(),
                    'email' => $student->email,
                    'password' => Str::password(40),   // unusable until the student sets one
                    'role' => Role::Student,
                    'status' => UserStatus::Invited,
                ])->save();
            });
            $student->forceFill(['user_id' => $user->id])->save();

            $token = Password::broker()->createToken($user);
            $user->notify(new InvitationNotification($token));

            $this->audit->record('user.invited', 'user', $user->id, $student->id, null, null, $actor);

            return $user;
        });
    }
}
