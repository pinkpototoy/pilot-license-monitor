<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\LoginFailed;
use App\Domain\Auth\LoginService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    public function show()
    {
        return view('auth.login');
    }

    public function login(Request $request, LoginService $login, AuditLogger $audit)
    {
        $data = $request->validate(['email' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', 'max:1000']]);

        try {
            $user = $login->attempt($data['email'], $data['password'], $request->ip());
        } catch (LoginFailed $e) {
            return back()->withInput($request->only('email'))->withErrors(['email' => $e->getMessage()]);
        }

        Auth::login($user);
        $request->session()->regenerate();   // session fixation defence
        $request->session()->put('mfa_passed', false);
        $request->session()->put('session_started_at', now()->getTimestamp());

        if (! $user->mfa_enabled && ! $user->role->requiresMfa()) {
            $this->completeLogin($request, $audit);
        }

        return redirect()->intended(route('home'));
    }

    public function logout(Request $request, AuditLogger $audit)
    {
        $user = $request->user();
        $audit->record('auth.logout', 'user', $user->id, $user->student?->id, null, null, $user);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'You are signed out.');
    }

    public static function completeLogin(Request $request, AuditLogger $audit): void
    {
        $user = $request->user();
        $request->session()->regenerate();
        $request->session()->put('mfa_passed', true);
        $user->forceFill(['last_login_at' => now()])->save();
        $audit->record('auth.login', 'user', $user->id, $user->student?->id, null, null, $user);
    }
}
