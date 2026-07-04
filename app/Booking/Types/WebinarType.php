<?php

declare(strict_types=1);

namespace App\Booking\Types;

use App\Booking\Contracts\BookingTypeInterface;

final class WebinarType implements BookingTypeInterface
{
    public const string KEY = 'webinar';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Webinar';
    }

    public function isPaid(): bool
    {
        return false;
    }

    public function defaultDurationMinutes(): int
    {
        return 90;
    }

    public function maxAttendees(): ?int
    {
        return null;
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function rules(): array
    {
        return [];
    }

    public function formRules(): array
    {
        return [
            'meta.topic' => ['required', 'string', 'max:255'],
        ];
    }
}
