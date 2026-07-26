<?php

declare(strict_types=1);

namespace App\Earnings\Enums;

/**
 * Rollout POLICY, not a kill switch — `payout_execution_enabled`
 * remains the authoritative switch. This narrows what a route
 * resolution is even allowed to consider once execution is turned on,
 * independent of which provider adapters are registered.
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
