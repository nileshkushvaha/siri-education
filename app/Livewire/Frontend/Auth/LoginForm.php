<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Auth;

use App\Enums\LoginResult;
use App\Http\Requests\Auth\LoginRequest;
use App\Livewire\Frontend\Auth\Concerns\ThrottlesLivewireRequests;
use App\Models\User;
use App\Services\Auth\LoginService;
use App\Services\Auth\VerificationResendService;
use App\Services\PortalResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

/**
 * Thin Livewire replacement for the classic POST /login form. All
 * authentication logic stays in LoginService (account locking, failed-
 * attempt tracking, portal checks) — this component only orchestrates,
 * mirroring exactly what LoginController::store() does, since that
 * controller (and its extensively tested POST route) is left in place
 * unchanged as the non-JS/API fallback.
 */
final class LoginForm extends Component
{
    use ThrottlesLivewireRequests;

    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public ?string $bannerType = null; // 'locked' | 'unverified' | null

    public ?string $bannerMessage = null;

    public ?string $unverifiedEmail = null;

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return (new LoginRequest)->rules();
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return (new LoginRequest)->messages();
    }

    public function login(LoginService $loginService, PortalResolver $portal): void
    {
        $this->bannerType = null;
        $this->bannerMessage = null;

        $this->validate();
        $this->throttleLimiter('login', ['email' => $this->email], 'email');

        // Admin-portal roles authenticate through /admin/login — one
        // login door per portal. Checked before attempting credentials
        // so no session is ever created here for them.
        $candidate = User::where('email', strtolower($this->email))->first();

        if ($candidate && $portal->usesAdminPortal($candidate)) {
            $this->redirect(route('filament.admin.auth.login'), navigate: false);

            return;
        }

        $result = $loginService->attempt(
            email: $this->email,
            password: $this->password,
            remember: $this->remember,
            ipAddress: request()->ip() ?? '127.0.0.1',
            userAgent: request()->userAgent() ?? '',
            sessionId: session()->getId(),
        );

        if ($result->isSuccessful()) {
            // session() (container-resolved) rather than request()->session():
            // Livewire's component-call lifecycle doesn't always bind a
            // session store onto the current Request instance the way the
            // full HTTP kernel does for a classic POST.
            session()->regenerate();

            $this->redirect(session()->pull('url.intended', $portal->loginRedirect(Auth::user())), navigate: false);

            return;
        }

        if ($result === LoginResult::EmailUnverified) {
            $this->bannerType = 'unverified';
            $this->bannerMessage = $result->message();
            $this->unverifiedEmail = $this->email;

            return;
        }

        // A brand-new registration is rejected as "inactive" before its
        // password is ever checked, and the generic inactive copy ("contact
        // support") is a dead end for someone who simply lost the mail.
        // LoginService has already re-sent it by this point, so say so and
        // offer the resend control rather than the locked banner.
        if ($result === LoginResult::AccountInactive
            && app(VerificationResendService::class)->eligible($candidate)) {
            $this->bannerType = 'unverified';
            $this->bannerMessage = 'Your email address is not verified yet. We have sent a fresh verification link — please check your inbox.';
            $this->unverifiedEmail = $this->email;

            return;
        }

        if (in_array($result, [LoginResult::AccountLocked, LoginResult::AccountBlocked, LoginResult::AccountInactive], true)) {
            $this->bannerType = 'locked';
            $this->bannerMessage = $result->message();

            return;
        }

        $errorMessage = $result->message();

        if ($result === LoginResult::InvalidCredentials) {
            $remaining = session('login_remaining_attempts');

            if ($remaining !== null && $remaining > 0) {
                $word = $remaining === 1 ? 'attempt' : 'attempts';
                $errorMessage .= " {$remaining} {$word} remaining.";
            }
        }

        $this->addError('email', $errorMessage);
    }

    /** Mirrors the existing POST /resend-verification-email closure route (routes/web.php), which stays in place unchanged as the non-JS fallback. */
    public function resendVerification(): void
    {
        $key = 'resend-verification-guest:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 3)) {
            $this->addError('email', 'Too many attempts. Please try again in a minute.');

            return;
        }

        RateLimiter::hit($key, 60);

        $user = User::where('email', strtolower((string) $this->unverifiedEmail))
            ->whereNull('email_verified_at')
            ->first();

        // Never mails a blocked, suspended or previously-active account —
        // and the message below stays identical either way, so the response
        // cannot be used to probe an account's status.
        app(VerificationResendService::class)->resendIfEligible($user);

        $this->bannerType = null;
        $this->bannerMessage = 'Verification email sent! Please check your inbox.';
    }

    public function render(): View
    {
        return view('livewire.frontend.auth.login-form');
    }
}
