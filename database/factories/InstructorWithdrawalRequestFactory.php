<?php

namespace Database\Factories;

use App\Earnings\Enums\InstructorWithdrawalStatus;
use App\Earnings\Enums\PayoutMethodType;
use App\Models\InstructorPayoutMethod;
use App\Models\InstructorWithdrawalRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InstructorWithdrawalRequest>
 */
class InstructorWithdrawalRequestFactory extends Factory
{
    public function definition(): array
    {
        $amount = 50000;

        return [
            'reference' => 'WD-'.now()->format('Ym').'-'.strtoupper(Str::random(8)),
            'instructor_id' => User::factory(),
            'payout_method_id' => fn (array $attributes) => InstructorPayoutMethod::factory()->verified()->create([
                'instructor_id' => $attributes['instructor_id'],
            ])->id,
            'currency_code' => 'INR',
            'amount_minor' => $amount,
            'fee_minor' => 0,
            'net_amount_minor' => $amount,
            'available_balance_before_minor' => $amount * 2,
            'available_balance_after_minor' => $amount,
            'payout_method_type' => PayoutMethodType::BankTransfer,
            'payout_method_label' => 'Bank Transfer ending in 1234',
            'masked_identifier' => 'Account ending in 1234',
            'encrypted_payout_method_snapshot' => [
                'schema_version' => 1,
                'payout_method_type' => 'bank_transfer',
                'currency_code' => 'INR',
                'masked_identifier' => 'Account ending in 1234',
                'account_holder_name' => 'Factory Instructor',
                'bank_name' => 'Test Bank',
                'account_number' => '9999991234',
                'routing_type' => 'ifsc',
                'routing_number' => 'TEST0001234',
                'captured_at' => now()->toIso8601String(),
            ],
            'status' => InstructorWithdrawalStatus::Submitted,
            'requested_at' => now(),
        ];
    }

    public function underReview(): static
    {
        return $this->state([
            'status' => InstructorWithdrawalStatus::UnderReview,
            'review_started_at' => now(),
        ]);
    }

    public function approved(): static
    {
        return $this->state([
            'status' => InstructorWithdrawalStatus::Approved,
            'review_started_at' => now()->subHour(),
            'approved_at' => now(),
        ]);
    }
}
