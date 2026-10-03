<?php

namespace App\Domain\Auth;

use App\Domain\Audit\AuditLogger;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/** FR-002, FR-003, BR-040 — TOTP (RFC 6238) with single-use recovery codes. */
class MfaService
{
    public function __construct(
        private readonly Google2FA $totp,
        private readonly AuditLogger $audit,
    ) {}

    public function newSecret(): string
    {
        return $this->totp->generateSecretKey(32);
    }

    public function qrSvg(User $user, string $secret): string
    {
        $uri = $this->totp->getQRCodeUrl(config('app.name'), $user->email, $secret);
        $writer = new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd));

        return $writer->writeString($uri);
    }

    /**
     * Confirm enrolment with a first valid code; returns the plain recovery codes (shown once).
     *
     * @return list<string>|null
     */
    public function enable(User $user, string $secret, string $code): ?array
    {
        if (! $this->checkCode($user->id, $secret, $code)) {
            return null;
        }
        $plain = collect(range(1, 8))->map(fn () => Str::upper(Str::random(5).'-'.Str::random(5)))->all();
        $user->forceFill([
            'mfa_secret' => $secret,
            'mfa_enabled' => true,
            'mfa_recovery_codes' => array_map(fn ($c) => Hash::make($c), $plain),
        ])->save();
        $this->audit->record('auth.mfa_enrolled', 'user', $user->id, $user->student?->id, null, null, $user);

        return $plain;
    }

    public function verify(User $user, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code);

        if (preg_match('/^\d{6}$/', $code)) {
            return $user->mfa_secret !== null && $this->checkCode($user->id, $user->mfa_secret, $code);
        }

        return $this->useRecoveryCode($user, $code);
    }

    /** Admin reset (e.g., lost phone): the user must enrol again at next sign-in. */
    public function reset(User $user, User $admin, string $reason): void
    {
        $user->forceFill(['mfa_secret' => null, 'mfa_enabled' => false, 'mfa_recovery_codes' => null])->save();
        $this->audit->record('auth.mfa_reset', 'user', $user->id, $user->student?->id, null, $reason, $admin);
    }

    private function checkCode(int $userId, string $secret, string $code): bool
    {
        $timestamp = $this->totp->verifyKeyNewer($secret, $code, Cache::get("mfa-last-ts:{$userId}"), 1);
        if ($timestamp === false) {
            return false;
        }
        // Replay protection: a code's time step can be used only once.
        Cache::put("mfa-last-ts:{$userId}", $timestamp === true ? $this->totp->getTimestamp() : $timestamp, now()->addMinutes(5));

        return true;
    }

    private function useRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->mfa_recovery_codes ?? [];
        foreach ($codes as $i => $hash) {
            if (Hash::check(Str::upper($code), $hash)) {
                unset($codes[$i]);
                $user->forceFill(['mfa_recovery_codes' => array_values($codes)])->save();
                $this->audit->record('auth.recovery_code_used', 'user', $user->id, $user->student?->id, null,
                    count($codes).' recovery codes remaining', $user);

                return true;
            }
        }

        return false;
    }
}
