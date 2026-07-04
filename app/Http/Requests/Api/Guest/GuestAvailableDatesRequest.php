<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Guest;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class GuestAvailableDatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::exists('booking_types', 'key')->where('is_active', true)],
            'subject' => ['required', 'string', 'max:100'],
            'grade' => ['required', 'integer', 'between:1,12'],
            'from' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.now()->addDays(90)->toDateString()],
            'timezone' => ['sometimes', 'timezone:all'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $from = $this->date('from');
            $to = $this->date('to');

            if ($from !== null && $to !== null && $from->diffInDays($to) > 31) {
                $validator->errors()->add('to', 'The date range may not exceed 31 days.');
            }
        });
    }
}
