<?php

declare(strict_types=1);

namespace App\Content\Redirects\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A redirect must never send a visitor into an
 * admin, API, authentication, storage, asset, webhook, or (live-
 * re-authorized) download route. Resolved against the app's ACTUAL
 * registered route collection/middleware rather than a hand-maintained
 * path list, so it can never silently drift from the real routes.
 */
final class RedirectDestinationGuard
{
    /** @var list<string> */
    private const array PROHIBITED_FIRST_SEGMENTS = [
        'admin', 'api', 'storage', 'build', 'livewire', 'up', 'webhooks',
    ];

    public function isProhibited(string $path): bool
    {
        $path = ltrim($path, '/');
        $firstSegment = strtolower((string) strtok($path, '/'));

        if (in_array($firstSegment, self::PROHIBITED_FIRST_SEGMENTS, true)) {
            return true;
        }

        $route = $this->matchRoute($path);

        if ($route === null) {
            // No matching named route — a public CMS page/subject/blog
            // slug that doesn't exist as a route is exactly what
            // redirects are FOR; nothing to prohibit here.
            return false;
        }

        $name = $route->getName() ?? '';

        // Auth routes (login/register/logout/verify-email/...) live at
        // top-level paths, not under an /auth prefix — identified by
        // route name instead. filament.* covers the whole admin panel
        // regardless of URI shape.
        if (str_starts_with($name, 'auth.') || str_starts_with($name, 'filament.')) {
            return true;
        }

        $middleware = $route->gatherMiddleware();

        // 'auth' covers every authenticated area (dashboard, profile,
        // instructor/student workspaces) — a public SEO redirect must
        // never target a private route. 'signed' covers any
        // signed-URL-protected route, the other concrete shape a
        // "signed-download route" could take in this app.
        return in_array('auth', $middleware, true) || in_array('signed', $middleware, true);
    }

    private function matchRoute(string $path): ?\Illuminate\Routing\Route
    {
        try {
            return Route::getRoutes()->match(Request::create('/'.$path, 'GET'));
        } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
            return null;
        }
    }
}
