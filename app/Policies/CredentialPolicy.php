<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Credential;
use App\Models\User;

/** SRS 9 permission matrix — credentials and dates. */
class CredentialPolicy
{
    public function view(User $user, Credential $credential): bool
    {
        return $user->hasRole(Role::Admin, Role::Staff, Role::Viewer)
            || ($user->role === Role::Student && $credential->student?->user_id === $user->id);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::Admin, Role::Staff);
    }

    public function update(User $user, Credential $credential): bool
    {
        return $user->hasRole(Role::Admin, Role::Staff);
    }

    /** UC-17 / BR-018. */
    public function override(User $user, Credential $credential): bool
    {
        return $user->role === Role::Admin;
    }
}
