<?php

namespace Database\Factories;

use App\Models\Holiday;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Holiday>
 */
class HolidayFactory extends Factory
{
    public function definition(): array
    {
        return [
            'date' => fake()->unique()->dateTimeBetween('+1 month', '+1 year')->format('Y-m-d'),
            'name' => ucfirst(fake()->words(2, true)),
        ];
    }
}
