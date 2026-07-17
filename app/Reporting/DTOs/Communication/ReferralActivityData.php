<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Communication;

/**
 * Phase 18G — referral activity. Version 1 has NO referral domain: no
 * codes, no attribution, no campaigns, no reward-status records (only
 * ReferralSettings configuration and the `referral_credit` wallet
 * ledger entry type). The wallet ledger is therefore the single
 * authoritative referral source: a referral reward exists only when a
 * `referral_credit` ledger credit confirms it. Conversion rate,
 * campaign performance, top referrers, abuse flags and
 * "referral-generated revenue" are all structurally unavailable and
 * deliberately absent (§4A gates failed — no denominator, no
 * attribution, no cost data). Amounts are integer minor units grouped
 * by currency, never summed across currencies.
 *
 * @param  array<string, int>  $creditedAmountByCurrency  currency => minor units (gross executed credits)
 */
final readonly class ReferralActivityData
{
    public function __construct(
        public int $creditsExecutedInPeriod,
        public array $creditedAmountByCurrency,
        public int $distinctRecipientsInPeriod,
        public int $reversalsInPeriod,
        public bool $referralModuleEnabled,
    ) {}
}
