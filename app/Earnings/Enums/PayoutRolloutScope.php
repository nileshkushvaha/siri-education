<?php

declare(strict_types=1);

namespace App\Earnings\Enums;

/**
 * Rollout POLICY, not a kill switch — `payout_execution_enabled`
 * remains the authoritative switch (false throughout Phase 16A/16A.1).
 * This narrows what a route resolution is even allowed to consider once
 * execution is eventually turned on, independent of whether any
 * specific provider adapter exists yet.
 */
enum PayoutRolloutScope: string
{
    case Disabled = 'disabled';
    case IndiaInrOnly = 'india_inr_only';
    case ProviderCapabilityRouting = 'provider_capability_routing';

    public function label(): string
    {
        return match ($this) {
            self::Disabled => 'Disabled — no payout route resolves',
            self::IndiaInrOnly => 'India / INR only',
            self::ProviderCapabilityRouting => 'Full country/currency capability routing',
        };
    }
}
