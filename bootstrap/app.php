<?php

use App\Domain\DomainRuleViolation;
use App\Http\Middleware\EnsureMfaComplete;
use App\Http\Middleware\EnsureStaffSide;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SessionTimeout;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(RequestId::class);
        $middleware->web(append: [SecurityHeaders::class, SessionTimeout::class]);
        $middleware->alias([
            'mfa' => EnsureMfaComplete::class,
            'staff' => EnsureStaffSide::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('home'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Business-rule failures render as ordinary field errors.
        $exceptions->render(function (DomainRuleViolation $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'type' => 'about:blank', 'title' => 'Business rule violation', 'status' => 422,
                    'detail' => $e->getMessage(), 'errors' => $e->errors, 'needs_confirmation' => $e->needsConfirmation,
                ], 422, ['Content-Type' => 'application/problem+json']);
            }

            return back()->withInput()->withErrors($e->errors)->with('needs_confirmation', $e->needsConfirmation);
        });
    })->create();
