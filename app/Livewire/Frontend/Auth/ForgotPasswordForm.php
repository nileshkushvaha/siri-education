<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Auth;

use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Livewire\Frontend\Auth\Concerns\ThrottlesLivewireRequests;
use App\Services\Auth\PasswordResetService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Thin Livewire replacement for the classic POST /forgot-password form.
 * PasswordResetService::sendResetLink() always "succeeds" from the
 * caller's perspective (enumeration protection) — mirrored here
 * exactly as ForgotPasswordController::store() does.
 */
final class ForgotPasswordForm extends Component
{
    use ThrottlesLivewireRequests;

    public string $email = '';

    public bool $sent = false;

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return (new ForgotPasswordRequest)->rules();
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return (new ForgotPasswordRequest)->messages();
    }

    public function send(PasswordResetService $passwordResetService): void
    {
        $this->validate();
        $this->throttleLimiter('password.reset', [], 'email');

        $passwordResetService->sendResetLink($this->email);

        $this->sent = true;
    }

    public function render(): View
    {
        return view('livewire.frontend.auth.forgot-password-form');
    }
}
