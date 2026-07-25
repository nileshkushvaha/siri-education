<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PromotionalCreditIssuance;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use App\PromotionalCredits\Enums\PromotionalCreditIssuanceType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PromotionalCreditIssuance>
 */
class PromotionalCreditIssuanceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'campaign_id' => null,
            'student_id' => User::factory(),
            'wallet_ledger_entry_id' => WalletLedgerEntry::factory(),
            'amount_minor' => 50000,
            'currency_code' => 'INR',
            'issuance_type' => PromotionalCreditIssuanceType::Manual,
            'issued_by' => User::factory(),
            'reason' => 'Goodwill credit for support issue.',
            'idempotency_key' => 'promo_credit:'.Str::uuid(),
            'issued_at' => now(),
        ];
    }
}
