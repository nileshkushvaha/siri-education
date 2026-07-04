<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Student;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StudentSlotsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::exists('booking_types', 'key')->where('is_active', true)],
            'teacher_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'timezone' => ['sometimes', 'timezone:all'],
        ];
    }
}
