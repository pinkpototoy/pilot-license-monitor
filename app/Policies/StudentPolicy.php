<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Student;
use App\Models\User;

/** SRS 9 permission matrix — students. Default deny. */
class StudentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(Role::Admin, Role::Staff, Role::Viewer);
    }

    public function view(User $user, Student $student): bool
    {
        return $user->hasRole(Role::Admin, Role::Staff, Role::Viewer)
            || ($user->role === Role::Student && $student->user_id === $user->id);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::Admin, Role::Staff);
    }

    public function update(User $user, Student $student): bool
    {
        return $user->hasRole(Role::Admin, Role::Staff);
    }

    public function deactivate(User $user, Student $student): bool
    {
        return $user->hasRole(Role::Admin, Role::Staff);
    }

    public function invite(User $user, Student $student): bool
    {
        return $user->hasRole(Role::Admin, Role::Staff);
    }

    /** Permanent delete / anonymize: Admin only (Phase 6 retention job). */
    public function anonymize(User $user, Student $student): bool
    {
        return $user->role === Role::Admin;
    }
}
