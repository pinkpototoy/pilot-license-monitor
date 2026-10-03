<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Audit\AuditLogger;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;

/** FR-004 — single-use, 30-minute reset links; also completes FR-008 invitations. */
class PasswordController extends Controller
{
    public function requestForm()
    {
        return view('auth.forgot-password');
    }

    public function sendLink(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink(['email' => mb_strtolower($request->input('email'))]);

        // Same response whether or not the account exists.
        return back()->with('status', 'If an account uses that email, a reset link is on its way. It expires in 30 minutes.');
    }

    public function resetForm(Request $request, string $token)
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function reset(Request $request, AuditLogger $audit)
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use ($audit) {
                $wasInvited = $user->status === UserStatus::Invited;
                $user->forceFill([
                    'password' => $password,
                    'password_changed_at' => now(),
                    'status' => UserStatus::Active,
                    'failed_login_count' => 0,
                    'locked_until' => null,
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();
                \DB::table('sessions')->where('user_id', $user->id)->delete();
                $audit->record($wasInvited ? 'user.invitation_accepted' : 'auth.password_reset', 'user', $user->id, $user->student?->id, null, null, $user);
            }
        );

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('status', 'Your password is set. Sign in to continue.')
            : back()->withErrors(['email' => 'This link is invalid or has expired. Request a new one.']);
    }
}
