<?php

declare(strict_types=1);

namespace App\Compliance\Enums;

enum SuspiciousActivityCategory: string
{
    case Auth = 'auth';
    case Booking = 'booking';
    case Referral = 'referral';
    case Wallet = 'wallet';

    public function label(): string
    {
        return match ($this) {
            self::Auth => 'Authentication',
            self::Booking => 'Booking',
            self::Referral => 'Referral',
            self::Wallet => 'Wallet',
        };
    }
}
