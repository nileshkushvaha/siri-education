<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Enums\ThemePreference;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateThemePreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'theme' => ['required', Rule::enum(ThemePreference::class)],
        ];
    }

    public function theme(): ThemePreference
    {
        return ThemePreference::from($this->validated('theme'));
    }
}
