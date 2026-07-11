<?php

namespace Database\Factories;

use App\Earnings\Enums\SettlementBatchStatus;
use App\Models\InstructorSettlementBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InstructorSettlementBatch>
 */
class InstructorSettlementBatchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'instructor_id' => User::factory(),
            'currency_code' => 'INR',
            'total_amount_minor' => 35000,
            'status' => SettlementBatchStatus::Draft,
        ];
    }

    public function approved(): static
    {
        return $this->state([
            'status' => SettlementBatchStatus::Approved,
            'approved_at' => now(),
            'approved_by' => User::factory(),
        ]);
    }
}
