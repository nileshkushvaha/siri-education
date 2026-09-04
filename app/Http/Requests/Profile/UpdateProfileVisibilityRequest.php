<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Enums\PortalAudience;
use App\Services\FrontendPortalAudienceResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileVisibilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && app(FrontendPortalAudienceResolver::class)->resolve($this->user()) === PortalAudience::Instructor;
    }

    public function rules(): array
    {
        return [
            'profile_visibility' => ['required', Rule::in(['public', 'private', 'members_only'])],
            'show_email' => ['nullable', 'boolean'],
            'show_phone' => ['nullable', 'boolean'],
            'show_social_links' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'show_email' => $this->boolean('show_email'),
            'show_phone' => $this->boolean('show_phone'),
            'show_social_links' => $this->boolean('show_social_links'),
        ]);
    }
}
