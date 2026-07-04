<?php

declare(strict_types=1);

namespace App\Booking\Enums;

enum BookingGuestStatus: string
{
    case Invited = 'invited';
    case Confirmed = 'confirmed';
    case Declined = 'declined';
    case Attended = 'attended';

    public function label(): string
    {
        return match ($this) {
            self::Invited => 'Invited',
            self::Confirmed => 'Confirmed',
            self::Declined => 'Declined',
            self::Attended => 'Attended',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Invited => 'warning',
            self::Confirmed => 'info',
            self::Declined => 'danger',
            self::Attended => 'success',
        };
    }
}
