<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletRecharge;
use App\Wallet\Enums\WalletRechargeStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WalletRecharge>
 */
class WalletRechargeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'wallet_id' => Wallet::factory(),
            'user_id' => User::factory(),
            'provider' => 'razorpay',
            'provider_order_id' => 'order_'.Str::upper(Str::random(14)),
            'provider_payment_id' => null,
            'amount_minor' => 50000,
            'currency_code' => 'INR',
            'status' => WalletRechargeStatus::ProviderCreated,
            'idempotency_key' => 'WRCH-'.strtoupper(Str::random(12)),
            'metadata' => [],
        ];
    }

    public function succeeded(): static
    {
        return $this->state(fn (): array => [
            'provider_payment_id' => 'pay_'.Str::upper(Str::random(14)),
            'status' => WalletRechargeStatus::Succeeded,
            'provider_confirmed_at' => now(),
            'succeeded_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => WalletRechargeStatus::Failed,
            'failed_at' => now(),
        ]);
    }
}
