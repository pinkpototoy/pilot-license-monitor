<?php

namespace App\Providers;

use App\Domain\Compliance\ValidityCalculator;
use App\Domain\Documents\ClamAvScanner;
use App\Domain\Documents\Scanner;
use App\Domain\Documents\SignatureScanner;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            ValidityCalculator::class,
            fn () => new ValidityCalculator(
                (bool) config('splms.expiry_date_is_valid_day')
            )
        );

        // FR-034: the malware scanner is chosen by configuration (SPLMS_SCANNER).
        $this->app->bind(
            Scanner::class,
            fn () => match (config('splms.documents.scanner')) {
                'clamav' => new ClamAvScanner,
                default => new SignatureScanner,
            }
        );
    }

    public function boot(): void
    {
        // Force HTTPS URLs in production.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // NFR-005: 12+ characters, checked against known breached passwords outside tests.
        Password::defaults(function () {
            $rule = Password::min(config('splms.password_min_length'));

            return $this->app->environment('testing')
                ? $rule
                : $rule->uncompromised();
        });

        // SRS 9 — capabilities that are not tied to one record.
        Gate::define(
            'view-dashboard',
            fn (User $u) => $u->hasRole(
                Role::Admin,
                Role::Staff,
                Role::Viewer
            )
        );

        Gate::define(
            'view-audit-log',
            fn (User $u) => $u->role === Role::Admin
        );

        Gate::define(
            'manage-users',
            fn (User $u) => $u->role === Role::Admin
        );

        Gate::define(
            'configure-system',
            fn (User $u) => $u->role === Role::Admin
        );
    }
}