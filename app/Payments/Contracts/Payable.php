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
 * Implementers are COMMERCIAL OBLIGATIONS, not the things being
 * bought:
 *
 *     App\Models\StudentPackagePurchase  (package purchase obligation)
 *     App\Models\BookingPayment          (booking payment obligation, PAY-4A)
 *
 * `Booking` itself deliberately does NOT implement this. A booking is
 * a lesson, not a debt: most bookings — package-funded, free demo,
 * not-required — are never payable at all, and making the lesson the
 * payable would imply every lesson can open a checkout. The obligation
 * row only exists when money is genuinely owed, which is exactly the
 * condition a Payable should encode.
 *
 * `WalletRecharge` also does not implement this — its legacy payment
 * path is untouched (see docs/generic-payable-payment-foundation.md).
 *
 * Transitional note: BookingPayment implements this contract, but live
 * Booking checkout still runs on its own legacy provider fields until
 * PAY-4B performs the cutover.
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
