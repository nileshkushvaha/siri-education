<?php

declare(strict_types=1);

namespace App\Wallet\Support;

use App\Models\Currency;
use App\Support\MoneyFormatter;

/**
 * Wallet-domain facade over the single canonical currency-aware
 * formatter (App\Support\MoneyFormatter). Kept for the wallet views'
 * existing call sites; float division is never performed here — every
 * money string in the application comes from one integer-safe
 * implementation.
 */
final class WalletMoneyFormatter
{
    public static function format(int $amountMinor, ?Currency $currency, string $fallbackCode = 'INR'): string
    {
        $code = $currency?->code ?? $fallbackCode;

        return MoneyFormatter::format($amountMinor, $code, $currency?->minor_units);
    }
}
