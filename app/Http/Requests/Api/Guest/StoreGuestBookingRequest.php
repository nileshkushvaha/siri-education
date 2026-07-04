<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Guest;

use App\Booking\Registry\BookingTypeRegistry;
use App\Rules\TurnstileToken;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreGuestBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(BookingTypeRegistry $registry): array
    {
        $type = (string) $this->input('type');

        return [
            'type' => ['required', 'string', Rule::exists('booking_types', 'key')->where('is_active', true)],
            'subject' => ['required', 'string', 'max:100'],
            'grade' => ['required', 'integer', 'between:1,12'],
            'starts_at' => ['required', 'date', 'after:now'],
            'timezone' => ['sometimes', 'timezone:all'],
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:1000'],
            // Honeypot: hidden field real users never fill. Bots do.
            'website' => ['prohibited'],
            // Turnstile — no-op unless BookingSettings::captcha_enabled.
            'cf_turnstile_response' => [new TurnstileToken],
            // Type drivers may demand extra fields (e.g. webinar meta.topic).
            ...($registry->has($type) ? $registry->get($type)->formRules() : []),
        ];
    }
}
