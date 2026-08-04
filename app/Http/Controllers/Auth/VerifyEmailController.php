<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PortalResolver;
use App\Support\PendingEmailVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The one-time-code verification screen.
 *
 * Deliberately reachable without a session-authenticated user: after
 * registration the account is NOT logged in (there is no signed URL to
 * make work any more), and the login flow lands an unverified user here
 * after their password checked out. Both paths mark the session via
 * PendingEmailVerification, which is the only thing that names the
 * account this screen operates on — see that class for why nothing else
 * may set it.
 *
 * An already-authenticated but unverified user (the
 * EnsureEmailVerifiedIfRequired middleware redirects them here) is
 * resolved from the guard instead.
 */
final class VerifyEmailController extends Controller
{
    public function __construct(
        private readonly PortalResolver $portal,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        $user = $this->resolveTarget($request);

        if ($user === null) {
            return redirect()->route('auth.login')
                ->with('error', 'Please sign in to continue verifying your email address.');
        }

        if ($user->hasVerifiedEmail()) {
            PendingEmailVerification::forget();

            return $request->user() !== null
                ? redirect($this->portal->loginRedirect($user))
                : redirect()->route('auth.login')
                    ->with('success', 'Your email address is already verified. Please sign in.');
        }

        return view('auth.verify-email', ['pendingUser' => $user]);
    }

    private function resolveTarget(Request $request): ?User
    {
        return $request->user() ?? PendingEmailVerification::user();
    }
}
