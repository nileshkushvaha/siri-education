<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Referral\Contracts\ReferralRewardServiceInterface;
use Illuminate\Console\Command;

/**
 * Credits delayed/retryable referral rewards through the
 * single creditReward() path. Idempotent (ledger idempotency keys +
 * row locks), bounded batches, per-reward failure isolated. Held
 * (fraud/currency) rewards are never selected — releasing a hold is a
 * separate, explicit action.
 */
class CreditEligibleReferralRewards extends Command
{
    protected $signature = 'referrals:credit-eligible-rewards {--limit=100 : Maximum rewards per run}';

    protected $description = 'Credit eligible referral rewards whose readiness time has passed';

    public function handle(ReferralRewardServiceInterface $rewards): int
    {
        $credited = $rewards->processReadyRewards((int) $this->option('limit'));

        $this->info(sprintf('Credited %d referral reward(s).', $credited));

        return self::SUCCESS;
    }
}
