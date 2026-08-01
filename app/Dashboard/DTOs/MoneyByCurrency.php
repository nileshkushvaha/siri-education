<?php

declare(strict_types=1);

namespace App\Dashboard\DTOs;

use App\Support\MoneyFormatter;

/**
 * A per-currency money figure. Currencies are NEVER summed: the
 * reporting layer returns money as `[currencyCode => minorAmount]`
 * maps precisely because no cross-currency total is defined anywhere
 * in this platform, and this DTO preserves that separation all the way
 * into the view.
 *
 * No instance of this class is ever labelled "revenue" — the platform
 * has no revenue-recognition definition (see
 * `App\Reporting\Registry\MetricRegistry`'s `gross_paid_booking_value`).
 */
final readonly class MoneyByCurrency
{
    public function __construct(
        public string $currencyCode,
        public int $amountMinor,
        public string $formatted,
    ) {}

    public static function make(string $currencyCode, int $amountMinor): self
    {
        return new self(
            currencyCode: $currencyCode,
            amountMinor: $amountMinor,
            formatted: MoneyFormatter::format($amountMinor, $currencyCode),
        );
    }

    /**
     * Maps a reporting-layer `[currency => minor]` array into a bounded,
     * display-ready list, largest first.
     *
     * @param  array<string, int|numeric-string>  $byCurrency
     * @return list<self>
     */
    public static function fromMap(array $byCurrency, int $limit = 3): array
    {
        $items = [];

        foreach ($byCurrency as $currency => $minor) {
            $items[] = self::make((string) $currency, (int) $minor);
        }

        usort($items, static fn (self $a, self $b): int => $b->amountMinor <=> $a->amountMinor);

        return array_slice($items, 0, $limit);
    }
}
