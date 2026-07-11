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
        $student = 50000;
        $earning = 35000;

        return [
            'lesson_id' => Lesson::factory(),
            'booking_id' => fn (array $attributes) => Lesson::query()->findOrFail($attributes['lesson_id'])->booking_id,
            'instructor_id' => User::factory(),
            'student_id' => User::factory(),
            'currency_code' => 'INR',
            'student_amount_minor' => $student,
            'earning_amount_minor' => $earning,
            'platform_margin_minor' => $student - $earning,
            'calculation_type' => EarningCalculationType::Percentage,
            'calculation_value' => 70,
            'status' => InstructorEarningStatus::PendingHold,
            'hold_until' => now()->addDays(7),
            'source_type' => 'lesson',
            'source_id' => fn (array $attributes) => $attributes['lesson_id'],
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
