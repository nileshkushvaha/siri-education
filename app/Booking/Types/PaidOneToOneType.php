<?php

declare(strict_types=1);

namespace App\Booking\Types;

use App\Booking\Contracts\BookingTypeInterface;

final class PaidOneToOneType implements BookingTypeInterface
{
    public const string KEY = 'paid_one_to_one';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Paid 1-to-1 Session';
    }

    public function isPaid(): bool
    {
        return true;
    }

    public function defaultDurationMinutes(): int
    {
        return 60;
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
        return [];
    }
}
