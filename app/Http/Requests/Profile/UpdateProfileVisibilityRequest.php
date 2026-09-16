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
        ];
    }
}
