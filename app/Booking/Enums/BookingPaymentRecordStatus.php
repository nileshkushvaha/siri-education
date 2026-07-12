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
    case Processing = 'processing';
    case Captured = 'captured';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Refunded = 'refunded';
    case Unknown = 'unknown';
    case ResolutionRequired = 'resolution_required';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Authorized => 'Authorized',
            self::Processing => 'Processing',
            self::Captured => 'Captured',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
            self::Refunded => 'Refunded',
            self::Unknown => 'Unknown',
            self::ResolutionRequired => 'Resolution required',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending, self::Authorized, self::Processing => 'warning',
            self::Captured => 'success',
            self::Failed, self::Expired => 'danger',
            self::Cancelled => 'gray',
            self::Refunded => 'info',
            self::Unknown, self::ResolutionRequired => 'danger',
        };
    }

    /**
     * A row in a terminal state has already settled and must never
     * transition again (e.g. a duplicate webhook is a no-op — see each
     * provider's settlePaymentRow()). Pending/Authorized/Processing are
     * awaiting an outcome; Unknown/ResolutionRequired are ambiguous
     * outcomes that reconciliation must resolve, not a settled state
     * either — none of these five stop future transitions.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Captured, self::Failed, self::Cancelled, self::Expired, self::Refunded => true,
            self::Pending, self::Authorized, self::Processing, self::Unknown, self::ResolutionRequired => false,
        };
    }
}
