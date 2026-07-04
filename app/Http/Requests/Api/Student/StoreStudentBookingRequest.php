<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Student;

use App\Booking\DTOs\RecurrenceData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreStudentBookingRequest extends FormRequest
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
            'starts_at' => ['required', 'date', 'after:now'],
            'timezone' => ['sometimes', 'timezone:all'],
            'subject' => ['nullable', 'string', 'max:100', 'required_with:grade'],
            'grade' => ['nullable', 'integer', 'between:1,12', 'required_with:subject'],
            'notes' => ['nullable', 'string', 'max:1000'],
            // Recurrence (optional)
            'recurring' => ['sometimes', 'boolean'],
            'occurrences' => ['required_if_accepted:recurring', 'integer', 'between:2,'.RecurrenceData::MAX_OCCURRENCES],
            'interval_weeks' => ['sometimes', 'integer', 'between:1,4'],
        ];
    }
}
