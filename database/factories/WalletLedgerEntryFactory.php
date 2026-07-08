<?php

namespace Database\Factories;

use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Wallet\Enums\WalletLedgerDirection;
use App\Wallet\Enums\WalletLedgerEntryType;
use App\Wallet\Enums\WalletLedgerStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WalletLedgerEntry>
 */
class WalletLedgerEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'wallet_id' => Wallet::factory(),
            'user_id' => fn (array $attributes) => Wallet::find($attributes['wallet_id'])?->user_id,
            'currency_id' => fn (array $attributes) => Wallet::find($attributes['wallet_id'])?->currency_id,
            'currency_code' => fn (array $attributes) => Wallet::find($attributes['wallet_id'])?->currency_code,
            'direction' => WalletLedgerDirection::Credit,
            'entry_type' => WalletLedgerEntryType::AdminAdjustment,
            'amount_minor' => 10000,
            'balance_after_minor' => 10000,
            'available_after_minor' => 10000,
            'held_after_minor' => 0,
            'status' => WalletLedgerStatus::Posted,
            'posted_at' => now(),
        ];
    }
}
