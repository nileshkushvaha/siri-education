<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Guest;

use Illuminate\Foundation\Http\FormRequest;

final class RescheduleGuestBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'size:64'],
            'starts_at' => ['required', 'date', 'after:now'],
            'timezone' => ['sometimes', 'timezone:all'],
            'reason' => ['nullable', 'string', 'max:500'],
            'website' => ['prohibited'],
        ];
    }
}
