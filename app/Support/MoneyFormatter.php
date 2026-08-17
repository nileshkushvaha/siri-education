<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Currency;
use InvalidArgumentException;

/**
 * The single currency-aware bridge between integer minor units and
 * display/input strings for the earnings & payout domain. The exponent
 * comes from the canonical `currencies.minor_units` column (JPY = 0,
 * USD/INR = 2, KWD = 3) — never a hardcoded "divide by 100".
 *
 * All conversion is integer/string arithmetic; PHP floats never touch a
 * canonical amount. Formatting is display-only — storage and arithmetic
 * stay integer minor units end to end.
 */
final class MoneyFormatter
{
    private const int MIN_EXPONENT = 0;

    private const int MAX_EXPONENT = 6;

    /** Per-request cache of currency exponents, keyed by uppercase code. */
    private static array $minorUnitsByCode = [];

    /** "12,345.67 INR" / "1,250 JPY" / "3.141 KWD" — integer math only. */
    public static function format(int $amountMinor, string $currencyCode, ?int $minorUnits = null): string
    {
        $currencyCode = strtoupper($currencyCode);
        $units = self::validateExponent($minorUnits ?? self::minorUnitsFor($currencyCode));

        $sign = $amountMinor < 0 ? '-' : '';
        $absolute = (string) abs($amountMinor);
        $absolute = str_pad($absolute, $units + 1, '0', STR_PAD_LEFT);

        $whole = substr($absolute, 0, strlen($absolute) - $units) ?: '0';
        $fraction = $units > 0 ? substr($absolute, -$units) : '';

        // Thousands grouping on the string form — no float round-trips.
        $grouped = strrev(implode(',', str_split(strrev($whole), 3)));

        return $sign.$grouped.($units > 0 ? '.'.$fraction : '').' '.$currencyCode;
    }

    /**
     * Major-unit input string → integer minor units. Rejects more
     * fraction digits than the currency supports rather than silently
     * truncating money.
     */
    public static function toMinor(string $amount, int $minorUnits): int
    {
        $units = self::validateExponent($minorUnits);
        $normalized = str_replace(',', '.', trim($amount));

        if (! preg_match('/^\d{1,12}(\.\d+)?$/', $normalized)) {
            throw new InvalidArgumentException('Enter a valid amount (e.g. 1500 or 1500.00).');
        }

        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');

        if (strlen($fraction) > $units) {
            throw new InvalidArgumentException(sprintf(
                'This currency supports at most %d decimal place%s.',
                $units,
                $units === 1 ? '' : 's',
            ));
        }

        $fraction = str_pad($fraction, $units, '0');

        return ((int) $whole) * (10 ** $units) + ($units > 0 ? (int) $fraction : 0);
    }

    /**
     * The exact inverse of toMinor(): integer minor units → a plain
     * major-unit string ("50000", 2 → "500.00"). Unlike format() there
     * is no thousands grouping and no currency code, because this feeds
     * amount INPUTS — a grouped, suffixed string round-trips back
     * through toMinor() as a validation error.
     *
     * String/integer arithmetic only, same as everything else here: a
     * float round-trip is exactly the rounding this class exists to
     * prevent.
     */
    public static function toMajor(int $amountMinor, int $minorUnits): string
    {
        $units = self::validateExponent($minorUnits);

        $sign = $amountMinor < 0 ? '-' : '';
        $absolute = str_pad((string) abs($amountMinor), $units + 1, '0', STR_PAD_LEFT);

        $whole = substr($absolute, 0, strlen($absolute) - $units) ?: '0';

        return $sign.$whole.($units > 0 ? '.'.substr($absolute, -$units) : '');
    }

    /** Canonical exponent for a currency code; conservative 2 when unknown. */
    public static function minorUnitsFor(string $currencyCode): int
    {
        $code = strtoupper($currencyCode);

        if (! array_key_exists($code, self::$minorUnitsByCode)) {
            self::$minorUnitsByCode[$code] = self::validateExponent(
                (int) (Currency::query()->where('code', $code)->value('minor_units') ?? 2),
            );
        }

        return self::$minorUnitsByCode[$code];
    }

    /** Tests and long-running workers can drop the per-request cache. */
    public static function flushCache(): void
    {
        self::$minorUnitsByCode = [];
    }

    private static function validateExponent(int $units): int
    {
        if ($units < self::MIN_EXPONENT || $units > self::MAX_EXPONENT) {
            throw new InvalidArgumentException(sprintf('Unsupported currency exponent %d (allowed %d–%d).', $units, self::MIN_EXPONENT, self::MAX_EXPONENT));
        }

        return $units;
    }
}
