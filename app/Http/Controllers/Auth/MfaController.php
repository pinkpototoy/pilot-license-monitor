<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\MfaService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class MfaController extends Controller
{
    public function enrollForm(Request $request, MfaService $mfa)
    {
        $user = $request->user();
        if ($user->mfa_enabled) {
            return redirect()->route('mfa.challenge');
        }
        $secret = $request->session()->get('mfa_pending_secret') ?? $mfa->newSecret();
        $request->session()->put('mfa_pending_secret', $secret);

        return view('auth.mfa-enroll', [
            'qr' => $mfa->qrSvg($user, $secret),
            'secret' => trim(chunk_split($secret, 4, ' ')),
        ]);
    }

    public function enroll(Request $request, MfaService $mfa, AuditLogger $audit)
    {
        $request->validate(['code' => ['required', 'string', 'max:20']]);
        $secret = $request->session()->get('mfa_pending_secret');
        $codes = $secret ? $mfa->enable($request->user(), $secret, preg_replace('/\s+/', '', $request->input('code'))) : null;

        if ($codes === null) {
            return back()->withErrors(['code' => 'That code did not match. Check your phone\'s clock and enter the current 6-digit code.']);
        }

        $request->session()->forget('mfa_pending_secret');
        LoginController::completeLogin($request, $audit);
        $request->session()->flash('recovery_codes', $codes);

        return redirect()->route('mfa.recovery-codes');
    }

    public function recoveryCodes(Request $request)
    {
        $codes = $request->session()->get('recovery_codes');
        if (! $codes) {
            return redirect()->route('home');
        }

        return view('auth.recovery-codes', ['codes' => $codes]);
    }

    public function challengeForm(Request $request)
    {
        if (! $request->user()->mfa_enabled) {
            return redirect()->route('mfa.enroll');
        }
        if ($request->session()->get('mfa_passed')) {
            return redirect()->route('home');
        }

        return view('auth.mfa-challenge');
    }

    public function challenge(Request $request, MfaService $mfa, AuditLogger $audit)
    {
        $request->validate(['code' => ['required', 'string', 'max:20']]);

        if (! $mfa->verify($request->user(), $request->input('code'))) {
            return back()->withErrors(['code' => 'That code did not work. Enter the current code from your authenticator app, or one of your recovery codes.']);
        }

        LoginController::completeLogin($request, $audit);

        return redirect()->intended(route('home'));
    }
}
