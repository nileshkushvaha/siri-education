<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Student;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StudentTeachersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::exists('booking_types', 'key')->where('is_active', true)],
            'subject' => ['required', 'string', 'max:100'],
            'grade' => ['required', 'integer', 'between:1,12'],
        ];
    }
}
