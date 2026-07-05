<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Currency>
 */
class CurrencyFactory extends Factory
{
    protected $model = Currency::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'name' => fake()->unique()->word().' currency',
            'symbol' => fake()->randomElement(['$', 'Rs', 'GBP', 'CAD', 'AUD']),
            'numeric_code' => str_pad((string) fake()->unique()->numberBetween(1, 999), 3, '0', STR_PAD_LEFT),
            'minor_units' => 2,
            'status' => 'active',
            'sort_order' => 0,
        ];
    }
}
