<?php

declare(strict_types=1);

namespace App\Booking\Types;

use App\Booking\Contracts\BookingTypeInterface;
use App\Booking\Validation\Rules\OneFreeDemoPerInstructorRule;

final class FreeDemoType implements BookingTypeInterface
{
    public const string KEY = 'free_demo';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Free Demo';
    }

    public function isPaid(): bool
    {
        return false;
    }

    public function defaultDurationMinutes(): int
    {
        return 30;
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function rules(): array
    {
        return [
            OneFreeDemoPerInstructorRule::class,
        ];
    }

    public function formRules(): array
    {
        return [];
    }
}
