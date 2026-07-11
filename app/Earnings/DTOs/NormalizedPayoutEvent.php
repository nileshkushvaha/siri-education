<?php

declare(strict_types=1);

namespace App\Earnings\DTOs;

use App\Earnings\Enums\InstructorPayoutAttemptStatus;
use Carbon\CarbonImmutable;

/**
 * A provider event, already verified and normalized by the adapter
 * before it ever reaches InstructorPayoutReconciliationService — event
 * signature/authenticity checking is entirely the adapter's concern.
 */
final readonly class NormalizedPayoutEvent
{
    public function __construct(
        public string $provider,
        public string $providerEventId,
        public string $eventType,
        public ?string $providerPayoutId,
        public InstructorPayoutAttemptStatus $attemptStatus,
        public ?int $amountMinor,
        public ?string $currencyCode,
        public CarbonImmutable $occurredAt,
        public string $payloadHash,
        public bool $signatureValid,
    ) {}
}
