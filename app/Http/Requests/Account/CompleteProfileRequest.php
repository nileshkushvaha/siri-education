<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Rules\SupportedRegistrationCountry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Same field rules as the register form (RegisterRequest) for the parts
 * a Google-registered student skipped — except the mobile number is
 * REQUIRED here, because this screen exists to collect it.
 */
class CompleteProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'country_id' => ['required', 'integer', app(SupportedRegistrationCountry::class)],
            'phone_country_iso2' => ['required', 'string', 'size:2', Rule::exists('countries', 'iso2')->where('status', 'active')],
            'phone' => ['required', 'string', 'max:20', 'regex:/^[+]?[\d\s\-().]{7,20}$/'],
            'terms' => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required' => 'Please enter your first name.',
            'country_id.required' => 'Please select your country.',
            'phone_country_iso2.required' => 'Please select the country code for your mobile number.',
            'phone.required' => 'Please enter your mobile number.',
            'phone.regex' => 'Please enter a valid mobile number.',
            'terms.accepted' => 'You must agree to the Terms and Conditions and Privacy Policy to continue.',
        ];
    }
}
