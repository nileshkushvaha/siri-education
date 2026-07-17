<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Finance;

use Carbon\CarbonImmutable;

/**
 * One row of the refund linkage table — safe references only (booking
 * reference, lesson id, disposition state, masked payment id), never
 * technical evidence, provider payloads, student contact details or
 * internal notes.
 */
final readonly class RefundLinkageRow
{
    public function __construct(
        public string $dispositionId,
        public string $bookingReference,
        public string $lessonId,
        public string $lessonOutcomeLabel,
        public string $processingStatusLabel,
        public ?string $reasonCode,
        public ?int $amountMinor,
        public ?string $currency,
        public CarbonImmutable $decidedAtUtc,
        public ?CarbonImmutable $executedAtUtc,
        public bool $adminHold,
        public ?string $maskedPaymentReference,
    ) {}
}
