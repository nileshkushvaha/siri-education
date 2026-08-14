<?php

declare(strict_types=1);

namespace App\Payments\Contracts;

/**
 * Phase 4B.1 — anything that can be paid for through the generic
 * payment path. Deliberately tiny: it exposes only what any payment
 * consumer inherently knows about itself, and nothing about how the
 * money is collected.
 *
 * Explicitly NOT on this contract (they belong to the payment
 * services/adapters, never the payable):
 *   - refund behaviour
 *   - webhook verification
 *   - provider order / payment ids
 *   - Razorpay- or Stripe-specific fields
 *   - checkout markup
 *   - any domain lifecycle (entitlements, bookings, …)
 *
 * Transitional note: `Booking` and `WalletRecharge` deliberately do
 * NOT implement this — their legacy payment paths are untouched (see
 * docs/generic-payable-payment-foundation.md). The first and currently
 * only implementer is App\Models\StudentPackagePurchase.
 */
interface Payable
{
    /** Stable morph alias for this payable type — never a FQCN (see PaymentServiceProvider's morph map). */
    public function paymentPayableType(): string;

    /** This payable's own primary key, as stored on payments.payable_id. */
    public function paymentPayableId(): string;

    /** Amount to collect, in the currency's minor units. Integer only — no floats ever touch an amount. */
    public function paymentAmountMinor(): int;

    /** ISO 4217 code, matching the amount above. */
    public function paymentCurrencyCode(): string;

    /** The user who owes/pays this amount — used for ownership checks at the service boundary. */
    public function paymentUserId(): int;

    /** Stable, human-traceable application reference for this payable (not a provider reference). */
    public function paymentReference(): string;

    /**
     * Non-sensitive context to persist alongside the attempt for
     * support/reconciliation. Must never contain credentials, card/UPI
     * details, or raw provider signatures.
     *
     * @return array<string, mixed>
     */
    public function paymentMetadata(): array;
}
