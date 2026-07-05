<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Pure wallet configuration — whether the Wallet module is enabled at
 * all is FeatureSettings::$wallet_enabled, not a field here.
 */
class WalletSettings extends Settings
{
    public float $minimum_recharge_amount;

    public float $maximum_recharge_amount;

    public float $low_balance_threshold;

    public int $recurring_deduction_hours_before_lesson;

    public static function group(): string
    {
        return 'wallet';
    }
}
