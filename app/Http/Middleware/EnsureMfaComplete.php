<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** FR-002 / BR-040: Admin and Staff cannot reach any page until MFA is enrolled and passed. */
class EnsureMfaComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || $request->session()->get('mfa_passed') === true) {
            return $next($request);
        }

        if ($user->mfa_enabled) {
            return redirect()->route('mfa.challenge');
        }

        if ($user->role->requiresMfa()) {
            return redirect()->route('mfa.enroll');
        }

        return $next($request);   // Students without optional MFA (FR-003)
    }
}
