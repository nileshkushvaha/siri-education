<?php

namespace Database\Factories;

use App\Models\Currency;
use App\Models\User;
use App\Models\Wallet;
use App\Wallet\Enums\WalletStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Wallet>
 */
class WalletFactory extends Factory
{
    public function definition(): array
    {
        // Reuses a single shared INR row instead of Currency::factory()'s
        // random unique code, so creating several wallets in one test
        // never collides on currencies.code's unique index.
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'INR'],
            ['name' => 'Indian Rupee', 'symbol' => '₹', 'minor_units' => 2, 'status' => 'active'],
        );

        return [
            'user_id' => User::factory(),
            'currency_id' => $currency->id,
            'currency_code' => $currency->code,
            'balance_minor' => 0,
            'available_balance_minor' => 0,
            'held_balance_minor' => 0,
            'status' => WalletStatus::Active,
        ];
    }

    public function frozen(): static
    {
        return $this->state(['status' => WalletStatus::Frozen]);
    }

    public function closed(): static
    {
        return $this->state(['status' => WalletStatus::Closed]);
    }
}
