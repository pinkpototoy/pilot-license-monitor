<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** FR-006: idle timeout by role and an absolute session lifetime. */
class SessionTimeout
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $session = $request->session();
        $now = now()->getTimestamp();
        $idleMinutes = $user->role->requiresMfa()
            ? config('splms.session.idle_minutes_staff')
            : config('splms.session.idle_minutes_student');

        $lastSeen = (int) $session->get('last_seen_at', $now);
        $startedAt = (int) $session->get('session_started_at', $now);

        if ($now - $lastSeen > $idleMinutes * 60 || $now - $startedAt > config('splms.session.absolute_hours') * 3600) {
            Auth::guard('web')->logout();
            $session->invalidate();
            $session->regenerateToken();

            return redirect()->route('login')->with('status', 'You were signed out after a period of inactivity. Sign in again to continue.');
        }

        $session->put('last_seen_at', $now);
        $session->put('session_started_at', $startedAt);

        return $next($request);
    }
}
