<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Guest;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class GuestAvailableSlotsRequest extends FormRequest
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
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'timezone' => ['sometimes', 'timezone:all'],
        ];
    }
}
