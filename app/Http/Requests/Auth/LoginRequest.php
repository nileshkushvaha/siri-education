<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Rules\ValidRegistrationCaptcha;
use App\Services\Auth\LoginChallengeService;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
            // Same challenge as registration, on its own session context —
            // but only once repeated failures from this origin warrant it.
            // A normal first sign-in is never asked.
            'captcha_answer' => app(LoginChallengeService::class)->requiresChallenge(request()->ip())
                ? ['required', ValidRegistrationCaptcha::forLogin()]
                : ['nullable'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Please enter your email address.',
            'email.email' => 'Please enter a valid email address.',
            'password.required' => 'Please enter your password.',
            'captcha_answer.required' => 'Please answer the security question.',
        ];
    }
}
