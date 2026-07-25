<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Currency;
use App\Models\PromotionalCreditCampaign;
use App\PromotionalCredits\Enums\PromotionalCreditCampaignStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromotionalCreditCampaign>
 */
class PromotionalCreditCampaignFactory extends Factory
{
    public function definition(): array
    {
        // Reuses a single shared INR row instead of Currency::factory()'s
        // random unique code, so creating several campaigns in one test
        // never collides on currencies.code's unique index.
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'INR'],
            ['name' => 'Indian Rupee', 'symbol' => '₹', 'minor_units' => 2, 'status' => 'active'],
        );

        return [
            'name' => 'Campaign '.fake()->unique()->numberBetween(1, 999999),
            'description' => fake()->sentence(),
            'status' => PromotionalCreditCampaignStatus::Draft,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(30),
            'amount_minor' => 50000,
            'currency_id' => $currency->id,
            'currency_code' => $currency->code,
            'per_student_limit' => 1,
            'total_budget_minor' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['status' => PromotionalCreditCampaignStatus::Active]);
    }
}
