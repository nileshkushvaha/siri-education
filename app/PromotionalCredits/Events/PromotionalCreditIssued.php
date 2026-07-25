<?php

declare(strict_types=1);

namespace App\PromotionalCredits\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Carries scalar ids only (mirrors ReferralRewardCredited) — safe queued serialization, no stale-model risk. */
final class PromotionalCreditIssued implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly string $issuanceId,
        public readonly int $studentId,
    ) {}
}
