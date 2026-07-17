<?php

declare(strict_types=1);

namespace App\Referral\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** After-commit reward lifecycle event — stable scalar identifiers only, never models. */
final class ReferralRewardReversed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $rewardId,
        public readonly int $referrerId,
        public readonly int $referredStudentId,
    ) {}
}
