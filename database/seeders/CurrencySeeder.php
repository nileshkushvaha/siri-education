<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    /**
     * The launch billing currencies.
     *
     * `minimumRechargeMinor` is SRS §13.12's own worked example — the
     * only recharge limits the specification actually states — expressed
     * in each currency's own minor units. NULL means the business has
     * not set a floor for that currency, and recharge then only requires
     * a positive amount; it is deliberately NOT a guessed default,
     * because an invented limit is indistinguishable later from one the
     * business chose. No maximum is seeded for any currency for the same
     * reason.
     *
     * These are seeded rather than left to the schema migration because
     * seeders run AFTER migrations: on a fresh install the currency rows
     * do not exist yet when that migration runs, so it can only backfill
     * an already-populated database.
     *
     * @var list<array{string, string, string, string, int, int, int|null}>
     */
    private const CURRENCIES = [
        ['INR', 'Indian Rupee', 'Rs', '356', 2, 10, 50000],
        ['USD', 'US Dollar', '$', '840', 2, 20, 1000],
        ['GBP', 'Pound Sterling', 'GBP', '826', 2, 30, 1000],
        ['CAD', 'Canadian Dollar', 'CAD', '124', 2, 40, null],
        ['AUD', 'Australian Dollar', 'AUD', '036', 2, 50, null],
        ['AED', 'UAE Dirham', 'AED', '784', 2, 60, null],
        ['SGD', 'Singapore Dollar', 'SGD', '702', 2, 70, null],
        ['NZD', 'New Zealand Dollar', 'NZD', '554', 2, 80, null],
        ['SAR', 'Saudi Riyal', 'SAR', '682', 2, 90, null],
    ];

    public function run(): void
    {
        foreach (self::CURRENCIES as [$code, $name, $symbol, $numericCode, $minorUnits, $sortOrder, $minimumRechargeMinor]) {
            $currency = Currency::query()->firstWhere('code', $code);

            $attributes = [
                'name' => $name,
                'symbol' => $symbol,
                'numeric_code' => $numericCode,
                'minor_units' => $minorUnits,
                'status' => 'active',
                'sort_order' => $sortOrder,
            ];

            // Recharge limits are operator-configurable on the Currency
            // admin form, so re-running this seeder must not overwrite a
            // figure someone deliberately set (or deliberately cleared).
            // Only a currency being created for the first time takes the
            // SRS default; the maximum is never seeded at all.
            if ($currency === null) {
                $attributes['minimum_recharge_minor'] = $minimumRechargeMinor;
            }

            Currency::updateOrCreate(['code' => $code], $attributes);
        }
    }
}
