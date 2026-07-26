<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileVisibilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'profile_visibility' => ['required', Rule::in(['public', 'private', 'members_only'])],
            'show_email' => ['nullable', 'boolean'],
            'show_phone' => ['nullable', 'boolean'],
            'show_social_links' => ['nullable', 'boolean'],
            // GAP-028 (SRS §12.19) — standing consent to being recorded
            // during lessons; opt-in, defaults false.
            'consents_to_recording' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'show_email' => $this->boolean('show_email'),
            'show_phone' => $this->boolean('show_phone'),
            'show_social_links' => $this->boolean('show_social_links'),
            'consents_to_recording' => $this->boolean('consents_to_recording'),
        ]);
    }
}
