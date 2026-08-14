<?php

declare(strict_types=1);

namespace App\Package\DTOs;

use App\Models\Payment;
use App\Models\StudentPackageEntitlement;
use App\Models\StudentPackagePurchase;

/**
 * The outcome of one settlement attempt.
 *
 * `settled` and `replayed` are deliberately separate: a replayed
 * webhook is a SUCCESS (the money is collected, the package is active)
 * that simply did no new work. Collapsing them would make the caller
 * choose between telling the provider "failed" — inviting a retry
 * storm — and losing the distinction in the audit trail.
 *
 * `ignored` covers events that were understood but not actionable, and
 * validation refusals such as an amount mismatch. Those must not be
 * retried either, but they are not successes.
 */
final readonly class PackageSettlementResult
{
    private function __construct(
        public ?Payment $payment,
        public ?StudentPackagePurchase $purchase,
        public ?StudentPackageEntitlement $entitlement,
        public bool $settled,
        public bool $replayed,
        public bool $ignored,
        public ?string $reason,
    ) {}

    public static function settled(Payment $payment, StudentPackagePurchase $purchase, StudentPackageEntitlement $entitlement): self
    {
        return new self($payment, $purchase, $entitlement, true, false, false, null);
    }

    /** Already fully settled by an earlier delivery of the same event. */
    public static function replayed(Payment $payment, StudentPackagePurchase $purchase, ?StudentPackageEntitlement $entitlement): self
    {
        return new self($payment, $purchase, $entitlement, false, true, false, 'Already settled.');
    }

    public static function ignored(?Payment $payment, ?StudentPackagePurchase $purchase, string $reason): self
    {
        return new self($payment, $purchase, null, false, false, true, $reason);
    }
}
