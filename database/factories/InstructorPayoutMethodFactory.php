<?php

namespace Database\Factories;

use App\Earnings\Enums\PayoutMethodStatus;
use App\Earnings\Enums\PayoutMethodType;
use App\Models\InstructorPayoutMethod;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InstructorPayoutMethod>
 */
class InstructorPayoutMethodFactory extends Factory
{
    public function definition(): array
    {
        $accountNumber = (string) $this->faker->numerify('##########');

        return [
            'instructor_id' => User::factory(),
            'type' => PayoutMethodType::BankTransfer,
            'currency_code' => 'INR',
            'display_label' => 'Bank Transfer ending in '.substr($accountNumber, -4),
            'masked_identifier' => 'Account ending in '.substr($accountNumber, -4),
            // Deterministic-per-row test fingerprint; real rows use the
            // keyed HMAC from PayoutMethodFingerprintService.
            'fingerprint' => hash('sha256', 'factory|'.$accountNumber),
            'encrypted_details' => [
                'account_holder_name' => $this->faker->name(),
                'bank_name' => 'Test Bank',
                'account_number' => $accountNumber,
                'routing_type' => 'ifsc',
                'routing_number' => 'TEST0001234',
                'iban' => null,
                'swift_bic' => null,
                'branch_name' => null,
                'account_type' => 'savings',
                'beneficiary_address' => null,
            ],
            'status' => PayoutMethodStatus::Draft,
        ];
    }

    public function pendingVerification(): static
    {
        return $this->state([
            'status' => PayoutMethodStatus::PendingVerification,
            'submitted_at' => now(),
        ]);
    }

    public function verified(): static
    {
        return $this->state([
            'status' => PayoutMethodStatus::Verified,
            'submitted_at' => now()->subDay(),
            'verified_at' => now(),
        ]);
    }

    public function rejected(string $reason = 'Account details could not be verified.'): static
    {
        return $this->state([
            'status' => PayoutMethodStatus::Rejected,
            'submitted_at' => now()->subDay(),
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }

    public function disabled(): static
    {
        return $this->state([
            'status' => PayoutMethodStatus::Disabled,
            'disabled_at' => now(),
        ]);
    }

    public function default(): static
    {
        return $this->state(['is_default' => true]);
    }
}
