<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    private const CURRENCIES = [
        ['INR', 'Indian Rupee', 'Rs', '356', 2, 10],
        ['USD', 'US Dollar', '$', '840', 2, 20],
        ['GBP', 'Pound Sterling', 'GBP', '826', 2, 30],
        ['CAD', 'Canadian Dollar', 'CAD', '124', 2, 40],
        ['AUD', 'Australian Dollar', 'AUD', '036', 2, 50],
    ];

    public function run(): void
    {
        foreach (self::CURRENCIES as [$code, $name, $symbol, $numericCode, $minorUnits, $sortOrder]) {
            Currency::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'symbol' => $symbol,
                    'numeric_code' => $numericCode,
                    'minor_units' => $minorUnits,
                    'status' => 'active',
                    'sort_order' => $sortOrder,
                ],
            );
        }
    }
}
