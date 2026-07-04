<?php

use App\Booking\Exceptions\BookingException;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureAdminPortal;
use App\Http\Middleware\EnsureEmailVerifiedIfRequired;
use App\Http\Middleware\EnsureFrontendPortal;
use App\Http\Middleware\EnsureLoginEnabled;
use App\Http\Middleware\EnsurePasswordChangeRequired;
use App\Http\Middleware\EnsureRegistrationEnabled;
use App\Http\Middleware\TrackUserSession;
use App\Providers\EventServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withEvents(false)
    ->withProviders([
        EventServiceProvider::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('admin') || $request->is('admin/*')) {
                return route('filament.admin.auth.login');
            }

            return route('auth.login');
        });

        $middleware->alias([
            'account.active' => EnsureAccountIsActive::class,
            'login.enabled' => EnsureLoginEnabled::class,
            'registration.enabled' => EnsureRegistrationEnabled::class,
            'email.verify.if.required' => EnsureEmailVerifiedIfRequired::class,
            'password.change.required' => EnsurePasswordChangeRequired::class,
            'session.track' => TrackUserSession::class,
            'frontend.portal' => EnsureFrontendPortal::class,
            'admin.portal' => EnsureAdminPortal::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Show a friendly page for expired/invalid verification links
        $exceptions->render(function (InvalidSignatureException $e, Request $request) {
            if ($request->is('auth/verify-email/*')) {
                return response()->view('auth.verification-expired', [], 403);
            }
        });

        // Domain failures (slot taken, duplicate, no teacher, …) are
        // client errors on the API and JSON endpoints, not server errors.
        $exceptions->render(function (BookingException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        });
    })->create();
