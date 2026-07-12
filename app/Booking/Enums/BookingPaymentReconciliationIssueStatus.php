<?php

declare(strict_types=1);

namespace App\Booking\Enums;

enum BookingPaymentReconciliationIssueStatus: string
{
    case Open = 'open';
    case Investigating = 'investigating';
    case Resolved = 'resolved';
    case Ignored = 'ignored';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Investigating => 'Investigating',
            self::Resolved => 'Resolved',
            self::Ignored => 'Ignored',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'danger',
            self::Investigating => 'warning',
            self::Resolved => 'success',
            self::Ignored => 'gray',
        };
    }

    public function isOpen(): bool
    {
        return match ($this) {
            self::Open, self::Investigating => true,
            default => false,
        };
    }
}
