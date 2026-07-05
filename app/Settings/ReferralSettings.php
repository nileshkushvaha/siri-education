<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Pure referral configuration — whether the Referral module is enabled
 * at all is FeatureSettings::$referral_enabled, not a field here.
 */
class ReferralSettings extends Settings
{
    public string $reward_type;

    public float $referrer_reward_amount;

    public float $referee_reward_amount;

    public int $reward_unlock_days;

    public static function group(): string
    {
        return 'referral';
    }
}
