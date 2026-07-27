<?php

use App\Booking\Exceptions\BookingException;
use App\Content\Redirects\Services\RedirectDestinationGuard;
use App\Content\Redirects\Services\RedirectService;
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
use Illuminate\Foundation\Exceptions\Renderer\Renderer;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

        // Applied to the whole 'web' group
        // (not listed per-route) so it also covers Livewire's update
        // endpoint, which only ever receives the base 'web' group and
        // never the app's own route-level middleware arrays. The
        // middleware itself no-ops for any unauthenticated/guest
        // request, so this is safe on public/guest-only pages too.
        $middleware->web(append: [
            TrackUserSession::class,
        ]);

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

        // GAP-036 (SRS §22.25/26) — public-only, runs before the friendly
        // 404 page below so a managed redirect always wins over a plain
        // "not found." Registered ahead of the generic HttpExceptionInterface
        // handler so it gets first refusal on every 404. Ignores admin/API/
        // internal routes via the same RedirectDestinationGuard target
        // validation reuses — a 404 inside those areas is never a
        // candidate for a public SEO redirect.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->isMethod('GET') || $request->is('api/*') || $request->expectsJson()) {
                return null;
            }

            if (app(RedirectDestinationGuard::class)->isProhibited($request->path())) {
                return null;
            }

            $resolution = app(RedirectService::class)->resolve($request->path());

            if ($resolution === null) {
                return null;
            }

            $target = $resolution['url'];

            if ($request->getQueryString()) {
                $target .= '?'.$request->getQueryString();
            }

            return redirect($target, $resolution['status']);
        });

        // Friendly web HTTP error pages are for non-debug environments only.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return null;
            }

            if (config('app.debug')) {
                $renderer = app(Renderer::class);

                return response($renderer->render($request, $e), $e->getStatusCode(), $e->getHeaders())
                    ->withException($e);
            }

            $status = $e->getStatusCode();

            if ($status < 400 || $status >= 500) {
                return null;
            }

            $view = view()->exists("errors.{$status}") ? "errors.{$status}" : 'errors.4xx';

            return response()->view($view, ['exception' => $e], $status, $e->getHeaders());
        });

        // Domain failures (slot taken, duplicate, no teacher, …) are
        // client errors on the API and JSON endpoints, not server errors.
        $exceptions->render(function (BookingException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        });
    })->create();
