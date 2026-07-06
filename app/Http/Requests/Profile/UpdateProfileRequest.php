<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            // Email is frozen — never accepted from the profile form. See
            // UpdateProfileAction, which never writes to users.email.
            'headline' => ['nullable', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:255'],
            'short_bio' => ['nullable', 'string', 'max:160'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:20'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other', 'prefer_not_to_say'])],
            'date_of_birth' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'state_id' => [
                'nullable',
                'integer',
                Rule::exists('states', 'id')->where(fn ($query) => $query->where('country_id', $this->input('country_id'))),
            ],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'timezone' => ['nullable', 'string', 'timezone:all'],
            'language' => ['nullable', 'string', 'max:10'],
            'student_academic_level_id' => ['nullable', 'uuid', 'exists:academic_levels,id'],
            'student_preferred_language_id' => ['nullable', 'integer', 'exists:languages,id'],
            'preferred_subject_ids' => ['nullable', 'array'],
            'preferred_subject_ids.*' => ['uuid', 'exists:subjects,id'],
            'website' => ['nullable', 'url', 'max:255'],
            'facebook' => ['nullable', 'url', 'max:255'],
            'twitter' => ['nullable', 'url', 'max:255'],
            'linkedin' => ['nullable', 'url', 'max:255'],
            'github' => ['nullable', 'url', 'max:255'],
            'instagram' => ['nullable', 'url', 'max:255'],
            'youtube' => ['nullable', 'url', 'max:255'],
            'email_notifications' => ['nullable', 'boolean'],
            'system_notifications' => ['nullable', 'boolean'],
            'marketing_emails' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required' => 'First name is required.',
            'date_of_birth.before' => 'Date of birth must be in the past.',
            'timezone.timezone' => 'Please select a valid timezone.',
            'country_id.exists' => 'Please select a valid country.',
            'state_id.exists' => 'Please select a state that belongs to the chosen country.',
            'student_academic_level_id.exists' => 'Please select a valid academic level.',
            'student_preferred_language_id.exists' => 'Please select a valid preferred language.',
            'preferred_subject_ids.*.exists' => 'Please select subjects from the catalog.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email_notifications' => $this->boolean('email_notifications'),
            'system_notifications' => $this->boolean('system_notifications'),
            'marketing_emails' => $this->boolean('marketing_emails'),
        ]);
    }
}
