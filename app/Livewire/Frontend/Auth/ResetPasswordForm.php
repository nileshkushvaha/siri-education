<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Auth;

use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\Auth\LoginService;
use App\Services\Auth\PasswordResetService;
use App\Services\PortalResolver;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Thin Livewire replacement for the classic POST /reset-password form.
 * Mirrors ResetPasswordController::store() exactly: reset via
 * PasswordResetService, then auto-login via LoginService — both
 * services own all the actual logic (token verification, password
 * history, activity log).
 */
final class ResetPasswordForm extends Component
{
    public string $token = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token, string $email = ''): void
    {
        $this->token = $token;
        $this->email = $email;
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return (new ResetPasswordRequest)->rules();
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return (new ResetPasswordRequest)->messages();
    }

    public function resetPassword(PasswordResetService $passwordResetService, LoginService $loginService, PortalResolver $portal): void
    {
        $this->validate();

        $error = $passwordResetService->resetPassword(
            email: $this->email,
            token: $this->token,
            password: $this->password,
            ipAddress: request()->ip() ?? '127.0.0.1',
        );

        if ($error !== null) {
            $this->addError('email', $error);

            return;
        }

        $loginService->attempt(
            email: $this->email,
            password: $this->password,
            remember: false,
            ipAddress: request()->ip() ?? '127.0.0.1',
            userAgent: request()->userAgent() ?? '',
        );

        session()->flash('success', 'Password reset successfully. Welcome back!');
        $this->redirect($portal->loginRedirect(auth()->user()), navigate: false);
    }

    public function render(): View
    {
        return view('livewire.frontend.auth.reset-password-form');
    }
}
