<?php

declare(strict_types=1);

namespace App\Booking\Enums;

/**
 * Lifecycle of a single gateway payment attempt (booking_payments row).
 * Distinct from BookingPaymentStatus, which is the booking's own
 * pending/paid/failed/refunded snapshot — this enum tracks the gateway
 * order/payment attempt itself, one-to-many per booking.
 */
enum BookingPaymentRecordStatus: string
{
    case Pending = 'pending';
    case Authorized = 'authorized';
    case Captured = 'captured';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Authorized => 'Authorized',
            self::Captured => 'Captured',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
            self::Refunded => 'Refunded',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending, self::Authorized => 'warning',
            self::Captured => 'success',
            self::Failed, self::Expired => 'danger',
            self::Cancelled => 'gray',
            self::Refunded => 'info',
        };
    }

    /** A row in this state may still transition (retry-safe to reuse). */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Captured, self::Failed, self::Cancelled, self::Expired, self::Refunded => true,
            self::Pending, self::Authorized => false,
        };
    }
}
