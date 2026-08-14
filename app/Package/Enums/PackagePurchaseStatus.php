<?php

declare(strict_types=1);

namespace App\Package\Enums;

/**
 * Lifecycle of the PURCHASE aggregate — deliberately not the lifecycle
 * of a payment attempt (App\Payments\Enums\PaymentStatus).
 *
 * There is intentionally no `Failed` case. A declined card is a failed
 * *attempt*, not a failed purchase: the student still owes the same
 * amount for the same accepted proposal and may simply try again.
 *
 *   Purchase                 pending_payment
 *     ├── Payment attempt #1 failed
 *     ├── Payment attempt #2 cancelled
 *     └── Payment attempt #3 pending
 *
 * Modelling failure on the purchase would either strand it in a dead
 * state after one decline, or force a second purchase row — which the
 * UNIQUE(proposal_id) index correctly forbids.
 *
 * There is also no `Cancelled` case: no product requirement for
 * abandoning an accepted purchase has been stated, and the proposal's
 * own Cancelled state already covers "the offer is off the table"
 * before acceptance. Adding one speculatively would create an
 * unreachable status.
 *
 * PendingPayment -> Paid is written only by verified settlement in
 * Phase 4B.3. Nothing in Phase 4B.2 performs that transition.
 */
enum PackagePurchaseStatus: string
{
    case PendingPayment = 'pending_payment';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Payment Pending',
            self::Paid => 'Paid',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PendingPayment => 'warning',
            self::Paid => 'success',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Paid;
    }

    /** Whether a student may still start or resume a payment attempt. */
    public function isPayable(): bool
    {
        return $this === self::PendingPayment;
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), strict: true);
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PendingPayment => [self::Paid],
            self::Paid => [],
        };
    }
}
