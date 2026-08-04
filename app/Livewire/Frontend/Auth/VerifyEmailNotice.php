<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Auth;

use App\Enums\Auth\EmailVerificationOutcome;
use App\Models\User;
use App\Services\Auth\EmailVerificationOtpService;
use App\Services\PortalResolver;
use App\Support\InstructorApplicationIntent;
use App\Support\PendingEmailVerification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Code entry and resend for email verification.
 *
 * The account is resolved the same way the page controller resolves it —
 * the authenticated user, or the account this session is allowed to
 * verify (PendingEmailVerification). Nothing here trusts a value posted
 * from the browser.
 *
 * On a correct code the user is signed in automatically: they have just
 * proven control of the mailbox, and the session was already tied to
 * this account by registration or by a successful password check.
 */
final class VerifyEmailNotice extends Component
{
    public string $code = '';

    /** '', 'code-sent', 'throttled' */
    public string $status = '';

    public string $banner = '';

    public function verify(EmailVerificationOtpService $otp, PortalResolver $portal): void
    {
        $this->status = '';
        $this->banner = '';

        $user = $this->targetUser();

        if ($user === null) {
            $this->redirect(route('auth.login'), navigate: false);

            return;
        }

        $this->validate(
            ['code' => ['required', 'digits:6']],
            ['code.required' => 'Enter the 6-digit code we emailed you.', 'code.digits' => 'The code is 6 digits.'],
        );

        try {
            $outcome = $otp->verify($user, $this->code, request()->ip() ?? '');
        } catch (ValidationException $e) {
            $this->code = '';
            $this->addError('code', $e->validator->errors()->first('code'));

            return;
        }

        $this->code = '';
        PendingEmailVerification::forget();

        // Verified, but the account still cannot be used (awaiting admin
        // approval, blocked, suspended). Never sign in — say what happened
        // and send them back to the login page.
        if (! $outcome->accountIsUsable()) {
            Auth::guard('web')->logout();
            session()->flash('success', $outcome->message());
            $this->redirect(route('auth.login'), navigate: false);

            return;
        }

        if (! Auth::check()) {
            Auth::login($user);
        }

        session()->regenerate();

        if (InstructorApplicationIntent::consume()) {
            session()->flash('success', 'Email verified! Continue your instructor application below.');
            $this->redirect(route('dashboard.instructor.onboarding'), navigate: false);

            return;
        }

        session()->flash('success', $outcome === EmailVerificationOutcome::AlreadyVerified
            ? 'Your email address is already verified.'
            : 'Email verified — welcome to '.config('app.name').'!');

        $this->redirect($portal->loginRedirect($user->refresh()), navigate: false);
    }

    public function resend(EmailVerificationOtpService $otp, PortalResolver $portal): void
    {
        $this->banner = '';
        $user = $this->targetUser();

        if ($user === null) {
            $this->redirect(route('auth.login'), navigate: false);

            return;
        }

        // Nothing left to verify — never issue a code for a verified address.
        if ($user->hasVerifiedEmail()) {
            PendingEmailVerification::forget();
            $this->redirect(Auth::check() ? $portal->loginRedirect($user) : route('auth.login'), navigate: false);

            return;
        }

        // Mirrors the throttle on the classic POST resend routes.
        $key = 'resend-verification:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($key, 6)) {
            $this->status = 'throttled';

            return;
        }

        RateLimiter::hit($key, 60);

        $otp->issue($user);

        $this->status = 'code-sent';
    }

    /** The account this screen is allowed to act on — never a posted value. */
    private function targetUser(): ?User
    {
        return Auth::user() ?? PendingEmailVerification::user();
    }

    public function render(): View
    {
        return view('livewire.frontend.auth.verify-email-notice');
    }
}
