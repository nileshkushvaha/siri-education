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
    /**
     * Recharge minimum/maximum deliberately do NOT live here. A limit is
     * an amount of money and cannot be expressed as one platform-wide
     * scalar across nine billing currencies with no exchange rate; they
     * are per-currency integer minor units on
     * `currencies.minimum_recharge_minor`/`maximum_recharge_minor`
     * (SRS §13.12), enforced by WalletRechargeService.
     */
    public float $low_balance_threshold;

    public int $recurring_deduction_hours_before_lesson;

    public static function group(): string
    {
        return 'wallet';
    }
}
