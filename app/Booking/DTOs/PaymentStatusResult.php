<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use App\Booking\Enums\BookingPaymentRecordStatus;
use Carbon\CarbonImmutable;

/**
 * The normalized result of a fetchStatus() reconciliation poll.
 *
 * PAY-1 (PAY-AUD-005): now carries the money the PROVIDER reports, so
 * the reconciliation path can make the same amount/currency comparison
 * the webhook path already makes. Both providers had the values in
 * hand and discarded them, which is why a payment recovered by the
 * scheduled sweep (or by "retry verification") could settle without
 * anyone checking what was actually collected.
 *
 * Nullable because a provider may legitimately have no money to report
 * yet for a created-but-unpaid order; a null is never treated as a
 * match.
 */
final readonly class PaymentStatusResult
{
    public function __construct(
        public BookingPaymentRecordStatus $recordStatus,
        public ?string $providerPaymentId,
        public ?string $providerStatus,
        public ?string $safeReason,
        public ?CarbonImmutable $providerOccurredAt = null,
        public ?int $verifiedAmountMinor = null,
        public ?string $verifiedCurrency = null,
    ) {}

    /** Did the provider report money at all? Only then is a comparison meaningful. */
    public function reportsMoney(): bool
    {
        return $this->verifiedAmountMinor !== null && $this->verifiedCurrency !== null;
    }
}
