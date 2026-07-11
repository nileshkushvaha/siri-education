<?php

declare(strict_types=1);

namespace App\Earnings\DTOs;

use App\Earnings\Enums\InstructorPayoutAttemptStatus;
use App\Earnings\Enums\PayoutFailureCategory;

/**
 * The normalized outcome of a single initiate() call. `attemptStatus`
 * is already mapped into the internal vocabulary — the caller never
 * sees a raw provider status string outside `providerStatus` (kept
 * only for display/audit, never branched on).
 */
final readonly class PayoutInitiationResult
{
    /** @param array<string, mixed> $safeMetadata */
    public function __construct(
        public InstructorPayoutAttemptStatus $attemptStatus,
        public ?string $providerPayoutId,
        public ?string $providerStatus,
        public ?string $safeReason,
        public ?PayoutFailureCategory $failureCategory,
        public array $safeMetadata = [],
    ) {}
}
