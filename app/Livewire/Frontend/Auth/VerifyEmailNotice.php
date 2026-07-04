<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

/**
 * Thin Livewire replacement for the classic POST /resend-verification
 * closure route (routes/web.php), which stays in place unchanged as
 * the non-JS fallback. All this does is call the same model method
 * the closure calls; the 6-per-minute throttle mirrors that route's
 * existing `throttle:6,1` middleware.
 */
final class VerifyEmailNotice extends Component
{
    public string $status = '';

    public function resend(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirect(route('dashboard'), navigate: false);

            return;
        }

        $key = 'resend-verification:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 6)) {
            $this->status = 'throttled';

            return;
        }

        RateLimiter::hit($key, 60);

        $user->sendEmailVerificationNotification();

        $this->status = 'verification-link-sent';
    }

    public function render(): View
    {
        return view('livewire.frontend.auth.verify-email-notice');
    }
}
