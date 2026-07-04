<?php

declare(strict_types=1);

namespace App\Booking\Types;

use App\Booking\Contracts\BookingTypeInterface;

final class ParentMeetingType implements BookingTypeInterface
{
    public const string KEY = 'parent_meeting';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Parent Meeting';
    }

    public function isPaid(): bool
    {
        return false;
    }

    public function defaultDurationMinutes(): int
    {
        return 30;
    }

    public function maxAttendees(): ?int
    {
        return 2;
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
