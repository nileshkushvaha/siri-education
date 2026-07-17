<?php

declare(strict_types=1);

namespace App\Referral\Enums;

/**
 * How a campaign's reward_value is interpreted. Percentage stores
 * integer basis points (500 = 5%) and follows the eligible lesson's
 * currency at calculation time; Fixed stores integer minor units in
 * the campaign's own reward currency. Never floats, never conversion.
 */
enum ReferralRewardType: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Percentage of lesson amount',
            self::Fixed => 'Fixed amount',
        };
    }
}
