<?php

declare(strict_types=1);

namespace App\Payments\Enums;

/**
 * Lifecycle of ONE generic payment attempt (see App\Models\Payment) —
 * not the lifecycle of the thing being paid for.
 *
 * Deliberately much smaller than the legacy BookingPaymentRecordStatus
 * (10 cases) and WalletRechargeStatus (9 cases). Each case here is
 * justified by behaviour that already exists in the shared gateway
 * layer; nothing speculative was carried over:
 *
 *  - `Processing` IS included: both live providers already emit it —
 *    Stripe's `payment_intent.processing` and Razorpay's `attempted`
 *    both map to a real in-flight state today.
 *  - `Authorized` is NOT included: BookingPaymentRecordStatus declares
 *    it but no provider ever assigns it, so it would be dead state.
 *  - `Refunded` is NOT included: refunds are out of scope for this
 *    phase, and a refund is arguably its own record rather than a
 *    mutation of the original attempt — deferred deliberately.
 *  - `Expired` is NOT included: gateway-attempt expiry is a distinct
 *    concern from package validity and is explicitly out of scope
 *    (see docs/architecture/payment-domain.md).
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Paid => 'Paid',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Processing => 'warning',
            self::Paid => 'success',
            self::Failed => 'danger',
            self::Cancelled => 'gray',
        };
    }

    /** A settled attempt — no further transition is possible. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Paid, self::Failed, self::Cancelled => true,
            default => false,
        };
    }

    /** Still awaiting a provider outcome; a retry must not be started while one of these is open. */
    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), strict: true);
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Processing, self::Paid, self::Failed, self::Cancelled],
            self::Processing => [self::Paid, self::Failed, self::Cancelled],
            self::Paid, self::Failed, self::Cancelled => [],
        };
    }
}
