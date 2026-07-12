<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use App\Booking\Enums\BookingPaymentRecordStatus;
use Carbon\CarbonImmutable;

/** The normalized result of a fetchStatus() reconciliation poll. */
final readonly class PaymentStatusResult
{
    public function __construct(
        public BookingPaymentRecordStatus $recordStatus,
        public ?string $providerPaymentId,
        public ?string $providerStatus,
        public ?string $safeReason,
        public ?CarbonImmutable $providerOccurredAt = null,
    ) {}
}
