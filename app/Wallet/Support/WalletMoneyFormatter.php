<?php

declare(strict_types=1);

namespace App\Wallet\Support;

use App\Models\Currency;

/**
 * The single place minor units are turned into a display string.
 * Never used for storage or arithmetic — those stay integer minor
 * units end to end.
 */
final class WalletMoneyFormatter
{
    public static function format(int $amountMinor, ?Currency $currency, string $fallbackCode = 'INR'): string
    {
        $units = $currency?->minor_units ?? 2;
        $major = $amountMinor / (10 ** $units);

        return number_format($major, $units).' '.($currency?->code ?? $fallbackCode);
    }
}
