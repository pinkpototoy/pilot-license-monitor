<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\RenewalCase;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/** SRS 9 — renewals. Students see only their own (404 otherwise, AC-03). Viewers see no documents. */
class RenewalCasePolicy
{
    public function view(User $user, RenewalCase $case): Response
    {
        if ($user->hasRole(Role::Admin, Role::Staff)) {
            return Response::allow();
        }

        return $this->owns($user, $case) ? Response::allow() : Response::denyAsNotFound();
    }

    public function act(User $user, RenewalCase $case): Response
    {
        return $this->view($user, $case);
    }

    public function review(User $user, ?RenewalCase $case = null): bool
    {
        return $user->hasRole(Role::Admin, Role::Staff);
    }

    public function release(User $user, RenewalCase $case): bool
    {
        return $user->role === Role::Admin;
    }

    private function owns(User $user, RenewalCase $case): bool
    {
        $case->loadMissing('credential.student');

        return $user->role === Role::Student && $case->credential->student->user_id === $user->id;
    }
}
