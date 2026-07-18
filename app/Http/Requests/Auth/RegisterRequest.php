<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Rules\SupportedRegistrationCountry;
use App\Rules\ValidRegistrationCaptcha;
use App\Services\Security\PasswordRuleBuilder;
use App\Settings\RegistrationSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(RegistrationSettings::class)->self_registration_enabled;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'string', 'email:rfc,dns', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[+]?[\d\s\-().]{7,20}$/'],
            'phone_country_iso2' => ['required', 'string', 'size:2', Rule::exists('countries', 'iso2')->where('status', 'active')],
            'country_id' => ['required', 'integer', app(SupportedRegistrationCountry::class)],
            'password' => [
                'required',
                'confirmed',
                app(PasswordRuleBuilder::class)->build(),
            ],
            'terms' => ['required', 'accepted'],
            'captcha_answer' => ['required', app(ValidRegistrationCaptcha::class)],
            // Deliberately lenient: a malformed or unknown referral code
            // must never block registration — attribution just silently
            // does not happen (SRS 16.32). Only an absurd length fails.
            'referral_code' => ['nullable', 'string', 'max:32'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required' => 'Please enter your first name.',
            'email.required' => 'Please enter your email address.',
            'email.unique' => 'This email is already registered. Please sign in instead.',
            'email.email' => 'Please enter a valid email address.',
            'phone.regex' => 'Please enter a valid phone number.',
            'country_id.required' => 'Please select your country.',
            'password.required' => 'Please create a password.',
            'password.confirmed' => 'Password confirmation does not match.',
            'terms.required' => 'You must accept the Terms of Service to continue.',
            'terms.accepted' => 'You must accept the Terms of Service to continue.',
            'captcha_answer.required' => 'Please answer the security question.',
        ];
    }

    public function attributes(): array
    {
        return [
            'first_name' => 'first name',
            'last_name' => 'last name',
            'password' => 'password',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (blank($this->input('phone_country_iso2'))) {
            $this->merge(['phone_country_iso2' => 'US']);
        }
    }
}
