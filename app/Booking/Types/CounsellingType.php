<?php

declare(strict_types=1);

namespace App\Booking\Types;

use App\Booking\Contracts\BookingTypeInterface;

final class CounsellingType implements BookingTypeInterface
{
    public const string KEY = 'counselling';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Counselling';
    }

    public function isPaid(): bool
    {
        return false;
    }

    public function defaultDurationMinutes(): int
    {
        return 45;
    }

    public function maxAttendees(): ?int
    {
        return 1;
    }

    public function requiresApproval(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function formRules(): array
    {
        return [];
    }
}
