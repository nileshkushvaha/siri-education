<?php

declare(strict_types=1);

namespace App\Earnings\DTOs;

use App\Earnings\Enums\InstructorPayoutAttemptStatus;
use App\Earnings\Enums\PayoutFailureCategory;
use Carbon\CarbonImmutable;

/** The normalized result of a fetchStatus() reconciliation poll. */
final readonly class PayoutStatusResult
{
    public function __construct(
        public InstructorPayoutAttemptStatus $attemptStatus,
        public ?string $providerPayoutId,
        public ?string $providerStatus,
        public ?string $safeReason,
        public ?PayoutFailureCategory $failureCategory,
        public ?CarbonImmutable $providerOccurredAt = null,
    ) {}
}
