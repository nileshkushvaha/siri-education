<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Communication;

/**
 * Phase 18G referral activity, extended by Phase 19D now that the
 * Referral domain exists (attributions, campaigns, reward records).
 * The wallet ledger remains the authority on executed credit VALUE;
 * the referral_rewards table is the authority on reward lifecycle
 * counts; referral_attributions on sign-up attribution. Referral
 * conversion rate is still deliberately absent — the Phase 18G §4A
 * definition gate (an agreed qualifying-event denominator) has not
 * been re-opened — and referred booking value is never labeled
 * revenue. Amounts are integer minor units grouped by currency, never
 * summed across currencies.
 *
 * @param  array<string, int>  $creditedAmountByCurrency  currency => minor units (gross executed credits)
 * @param  array<string, int>  $rewardsByStatus  status => count of reward rows created in the period
 * @param  array<string, int>  $reversedRewardAmountByCurrency  currency => minor units of reversed rewards
 */
final readonly class ReferralActivityData
{
    public function __construct(
        public int $creditsExecutedInPeriod,
        public array $creditedAmountByCurrency,
        public int $distinctRecipientsInPeriod,
        public int $reversalsInPeriod,
        public bool $referralModuleEnabled,
        public int $attributionsInPeriod = 0,
        public array $rewardsByStatus = [],
        public array $reversedRewardAmountByCurrency = [],
        public int $heldOrFailedRewardsOpen = 0,
    ) {}
}
