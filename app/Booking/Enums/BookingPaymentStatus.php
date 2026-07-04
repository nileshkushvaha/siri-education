<?php

declare(strict_types=1);

namespace App\Booking\Enums;

enum BookingPaymentStatus: string
{
    case NotRequired = 'not_required';
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::NotRequired => 'Not Required',
            self::Pending => 'Payment Pending',
            self::Paid => 'Paid',
            self::Failed => 'Payment Failed',
            self::Refunded => 'Refunded',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NotRequired => 'gray',
            self::Pending => 'warning',
            self::Paid => 'success',
            self::Failed => 'danger',
            self::Refunded => 'danger',
        };
    }

    /** Payment can still be attempted (initiate/retry). */
    public function isPayable(): bool
    {
        return match ($this) {
            self::Pending, self::Failed => true,
            default => false,
        };
    }
}
