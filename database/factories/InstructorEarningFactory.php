<?php

namespace Database\Factories;

use App\Earnings\Enums\EarningCalculationType;
use App\Earnings\Enums\InstructorEarningStatus;
use App\Models\InstructorEarning;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InstructorEarning>
 */
class InstructorEarningFactory extends Factory
{
    public function definition(): array
    {
        // Agreement-based hourly compensation — independent of student
        // pricing by construction.
        return [
            'lesson_id' => Lesson::factory(),
            'booking_id' => fn (array $attributes) => Lesson::query()->findOrFail($attributes['lesson_id'])->booking_id,
            'instructor_id' => User::factory(),
            'student_id' => User::factory(),
            'currency_code' => 'INR',
            'earning_amount_minor' => 35000,
            'calculation_type' => EarningCalculationType::Hourly,
            'status' => InstructorEarningStatus::PendingHold,
            'hold_until' => now()->addDays(7),
            'source_type' => 'lesson',
            'source_id' => fn (array $attributes) => $attributes['lesson_id'],
            'metadata' => [
                'pay_basis' => 'hourly',
                'rate_minor' => 35000,
                'eligible_minutes' => 60,
                'rounding_policy' => 'half_up_minor',
            ],
        ];
    }

    public function releasable(): static
    {
        return $this->state([
            'status' => InstructorEarningStatus::Releasable,
            'hold_until' => now()->subDay(),
            'released_at' => now()->subDay(),
        ]);
    }

    public function heldPastDue(): static
    {
        return $this->state([
            'status' => InstructorEarningStatus::PendingHold,
            'hold_until' => now()->subDay(),
        ]);
    }
}
