<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Auth;

use App\Enums\LoginResult;
use App\Http\Requests\Auth\LoginRequest;
use App\Livewire\Frontend\Auth\Concerns\ThrottlesLivewireRequests;
use App\Models\User;
use App\Services\Auth\LoginChallengeService;
use App\Services\Auth\LoginService;
use App\Services\Auth\RegistrationCaptchaService;
use App\Services\PortalResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
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

    public ?string $bannerType = null; // 'locked' | null

    public ?string $bannerMessage = null;

    public string $captcha_answer = '';

    public string $captchaQuestion = '';

    /** Only true once repeated failures from this origin warrant a challenge. */
    public bool $captchaRequired = false;

    public function mount(RegistrationCaptchaService $captcha, LoginChallengeService $challenge): void
    {
        $this->syncChallenge($captcha, $challenge);
    }

    /**
     * Issues a question only while one is actually required, so the form stays
     * short for the normal case and never renders a stale challenge.
     */
    private function syncChallenge(RegistrationCaptchaService $captcha, LoginChallengeService $challenge): void
    {
        $this->captchaRequired = $challenge->requiresChallenge(request()->ip());

        if ($this->captchaRequired && $this->captchaQuestion === '') {
            $this->captchaQuestion = $captcha->issue(RegistrationCaptchaService::LOGIN);
        }
    }

    public function refreshCaptcha(RegistrationCaptchaService $captcha): void
    {
        $this->captcha_answer = '';
        $this->resetValidation('captcha_answer');
        $this->captchaQuestion = $captcha->issue(RegistrationCaptchaService::LOGIN);
    }

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

        // Throttle BEFORE validating: an attempt must count against the
        // limiter whether or not the payload is well-formed, otherwise a
        // deliberately invalid field (now including the security question)
        // would be a way to keep guessing without ever being throttled.
        $this->throttleLimiter('login', ['email' => $this->email], 'email');
        $this->validate();

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

        // The password was correct but the address is unverified:
        // LoginService has issued a fresh code and marked this session as
        // allowed to verify the account, so continue on the code screen —
        // which signs the user in once the code checks out.
        if ($result === LoginResult::EmailUnverified) {
            session()->flash('success', 'Your email address is not verified yet. Enter the 6-digit code we just emailed you.');
            $this->redirect(route('auth.verification.notice'), navigate: false);

            return;
        }

        if (in_array($result, [LoginResult::AccountLocked, LoginResult::AccountBlocked, LoginResult::AccountInactive], true)) {
            $this->bannerType = 'locked';
            $this->bannerMessage = $result->message();

            return;
        }

        $this->captchaQuestion = '';
        $this->syncChallenge(app(RegistrationCaptchaService::class), app(LoginChallengeService::class));

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

    public function render(): View
    {
        return view('livewire.frontend.auth.login-form');
    }
}
