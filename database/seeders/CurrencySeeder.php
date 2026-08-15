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
        ['AED', 'UAE Dirham', 'AED', '784', 2, 60],
        ['SGD', 'Singapore Dollar', 'SGD', '702', 2, 70],
        ['NZD', 'New Zealand Dollar', 'NZD', '554', 2, 80],
        ['SAR', 'Saudi Riyal', 'SAR', '682', 2, 90],
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
