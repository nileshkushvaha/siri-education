<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\GoogleActivationResult;
use App\Http\Controllers\Controller;
use App\Services\Auth\GoogleActivationOutcome;
use App\Services\Auth\GoogleActivationService;
use App\Services\PortalResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * "Continue with Google" — Google account activation for frontend-portal
 * users. Thin: every decision lives in GoogleActivationService.
 */
class GoogleActivationController extends Controller
{
    public function __construct(
        private readonly GoogleActivationService $activation,
        private readonly PortalResolver $portal,
    ) {}

    public function redirect(): Response
    {
        if (! $this->activation->isEnabled()) {
            return $this->fail(GoogleActivationResult::Disabled);
        }

        // Identity only — never Drive/Gmail/Calendar. Stateful flow so
        // Socialite verifies the OAuth `state` on the way back (CSRF).
        return Socialite::driver('google')
            ->scopes(['openid', 'email', 'profile'])
            ->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        if (! $this->activation->isEnabled()) {
            return $this->fail(GoogleActivationResult::Disabled);
        }

        // User declined consent, or Google reported an error.
        if ($request->filled('error') || ! $request->filled('code')) {
            return $this->fail(GoogleActivationResult::OAuthFailed);
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            // Invalid/expired state, token exchange failure, network error.
            // Never log the authorization code or any token.
            Log::warning('Google activation callback failed.', ['exception' => $e::class, 'message' => $e->getMessage()]);

            return $this->fail(GoogleActivationResult::OAuthFailed);
        }

        $outcome = $this->activation->activate(
            $googleUser,
            $request->ip() ?? '127.0.0.1',
            $request->userAgent() ?? '',
            $request->session()->getId(),
        );

        if (! $outcome->isSuccessful()) {
            // Always back to the frontend login page: it is the only screen
            // that renders the flashed explanation (the Filament login does
            // not), and the message itself points admin roles to /admin.
            return $this->fail($outcome);
        }

        $request->session()->regenerate();

        // The dashboard stack's password.change.required middleware sends a
        // first-time user on to the set-password screen from here.
        return redirect()->intended($this->portal->loginRedirect(auth()->user()));
    }

    private function fail(GoogleActivationResult|GoogleActivationOutcome $result): RedirectResponse
    {
        return redirect()->route('auth.login')->with('error', $result->message());
    }
}
