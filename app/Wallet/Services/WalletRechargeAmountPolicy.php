<?php

declare(strict_types=1);

namespace App\Wallet\Services;

use App\Models\Currency;
use App\Support\MoneyFormatter;
use App\Wallet\Exceptions\WalletException;

/**
 * The per-currency recharge amount rules (Settings → Wallet): minimum,
 * maximum, and the step the amount must be a whole multiple of. All
 * integer minor units in the currency's own exponent. NULL on any rule
 * means "not configured", which is not the same as zero.
 */
final class WalletRechargeAmountPolicy
{
    public function assert(int $amountMinor, string $currencyCode): void
    {
        $currency = Currency::query()
            ->where('code', strtoupper($currencyCode))
            ->first(['minimum_recharge_minor', 'maximum_recharge_minor', 'recharge_multiple_minor']);

        if ($currency === null) {
            return;
        }

        if ($currency->minimum_recharge_minor !== null && $amountMinor < $currency->minimum_recharge_minor) {
            throw new WalletException(sprintf('The minimum recharge amount is %s.', MoneyFormatter::format($currency->minimum_recharge_minor, $currencyCode)));
        }

        if ($currency->maximum_recharge_minor !== null && $amountMinor > $currency->maximum_recharge_minor) {
            throw new WalletException(sprintf('The maximum recharge amount is %s.', MoneyFormatter::format($currency->maximum_recharge_minor, $currencyCode)));
        }

        $step = $currency->recharge_multiple_minor;

        if ($step !== null && $step > 0 && $amountMinor % $step !== 0) {
            throw new WalletException(sprintf('Recharge amounts must be in multiples of %s.', MoneyFormatter::format($step, $currencyCode)));
        }
    }
}
