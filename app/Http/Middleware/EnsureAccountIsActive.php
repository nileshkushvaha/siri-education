<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Student\StudentLifecycleService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    public function __construct(private readonly StudentLifecycleService $studentLifecycle) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = auth()->user();

        if (! $user) {
            return $next($request);
        }

        if ($user->isLocked()) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('auth.login')
                ->withErrors(['email' => 'Your account is temporarily locked. Please try again later or reset your password.']);
        }

        if ($user->isBlocked()) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('auth.login')
                ->withErrors(['email' => 'Your account has been suspended. Please contact support.']);
        }

        if (! $user->isActive()) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('auth.login')
                ->withErrors(['email' => 'Your account is inactive. Please contact support.']);
        }

        // Catches a student whose status was
        // suspended/archived mid-session. Normally StudentLifecycleService
        // already revokes the session at transition time, so this is
        // defense-in-depth for any request that slips through before that
        // takes effect (e.g. a different device's still-live session).
        if ($this->studentLifecycle->blocksLogin($user)) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('auth.login')
                ->withErrors(['email' => 'Your account is not available. Please contact support.']);
        }

        return $next($request);
    }
}
