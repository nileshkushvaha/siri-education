<?php

namespace Database\Factories;

use App\Earnings\Enums\CompensationAgreementStatus;
use App\Earnings\Enums\CompensationPayBasis;
use App\Models\InstructorCompensationAgreement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InstructorCompensationAgreement>
 */
class InstructorCompensationAgreementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'reference' => 'ICA-'.strtoupper(Str::random(10)),
            'instructor_id' => User::factory(),
            'pay_basis' => CompensationPayBasis::Hourly,
            'amount_minor' => 80000,
            'currency_code' => 'INR',
            'timezone' => 'Asia/Kolkata',
            'status' => CompensationAgreementStatus::Draft,
            'version' => 1,
            'effective_from' => now()->subMonth(),
            'internal_reason' => 'Factory-created agreement.',
        ];
    }

    public function active(): static
    {
        return $this->state([
            'status' => CompensationAgreementStatus::Active,
            'approved_at' => now()->subMonth(),
        ]);
    }

    public function daily(int $amountMinor = 200000): static
    {
        return $this->state([
            'pay_basis' => CompensationPayBasis::Daily,
            'amount_minor' => $amountMinor,
        ]);
    }

    public function weekly(int $amountMinor = 1000000): static
    {
        return $this->state([
            'pay_basis' => CompensationPayBasis::Weekly,
            'amount_minor' => $amountMinor,
        ]);
    }

    public function monthly(int $amountMinor = 4000000): static
    {
        return $this->state([
            'pay_basis' => CompensationPayBasis::Monthly,
            'amount_minor' => $amountMinor,
        ]);
    }
}
