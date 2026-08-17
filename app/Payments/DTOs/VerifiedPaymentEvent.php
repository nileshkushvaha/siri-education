<?php

declare(strict_types=1);

namespace App\Payments\DTOs;

use App\Payments\Enums\PaymentEventType;
use Illuminate\Support\Carbon;

/**
 * A provider settlement signal that has ALREADY been authenticated —
 * either by webhook signature verification or by an authenticated
 * provider fetch during reconciliation.
 *
 * This is the only shape any settlement service ever sees. Domain code
 * never parses a raw Stripe or Razorpay payload, and the same DTO is
 * produced by both the webhook path and the reconciliation path, so
 * there is exactly one settlement code path rather than two that must
 * be kept in agreement.
 *
 * Deliberately minimal: only the fields needed to identify the attempt
 * and to prove what was collected. No raw payload, no signature, no
 * provider secret is carried here or persisted anywhere.
 */
final readonly class VerifiedPaymentEvent
{
    public function __construct(
        public string $provider,
        public PaymentEventType $type,
        public ?string $reference = null,
        public ?string $providerOrderId = null,
        public ?string $providerPaymentId = null,
        public ?int $amountMinor = null,
        public ?string $currencyCode = null,
        public ?Carbon $occurredAt = null,
        public ?string $reason = null,
        /**
         * Which authenticated route produced this event — `webhook` or
         * `reconciliation`. Phase 4E.2 only: it is recorded as
         * discrepancy metadata so an operator can tell a provider
         * redelivery apart from a sweep re-detection. It must never
         * become a rule input: both routes are equally authenticated and
         * settle through identical logic, and branching on this would
         * recreate the two-code-paths problem this DTO exists to prevent.
         */
        public string $source = 'webhook',
    ) {}

    /**
     * The event a reconciliation poll proves, carrying what the PROVIDER
     * reported — never the attempt's own copy, which would make
     * settlement's mismatch guards compare the row with itself.
     *
     * Amount and currency are nullable because a provider can confirm a
     * payment without restating either; settlement treats a null as
     * unproven and skips that check rather than reading it as agreement.
     */
    public static function reconciled(
        string $provider,
        PaymentEventType $type,
        ?string $reference,
        ?string $providerOrderId,
        ?string $providerPaymentId,
        ?int $amountMinor,
        ?string $currencyCode,
    ): self {
        return new self(
            provider: $provider,
            type: $type,
            reference: $reference,
            providerOrderId: $providerOrderId,
            providerPaymentId: $providerPaymentId,
            amountMinor: $amountMinor,
            currencyCode: $currencyCode,
            occurredAt: now(),
            reason: 'Confirmed by reconciliation poll.',
            source: 'reconciliation',
        );
    }
}
