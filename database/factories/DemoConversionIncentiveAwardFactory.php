<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\DemoConversionIncentiveAward;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DemoConversionIncentiveAward>
 */
class DemoConversionIncentiveAwardFactory extends Factory
{
    public function definition(): array
    {
        $instructor = User::factory();
        $student = User::factory();

        return [
            'demo_booking_id' => Booking::factory(),
            'demo_lesson_id' => Lesson::factory(),
            'paid_booking_id' => Booking::factory(),
            'paid_lesson_id' => Lesson::factory(),
            'instructor_id' => $instructor,
            'student_id' => $student,
            'instructor_earning_id' => null,
            'amount_minor' => 20000,
            'currency_code' => 'INR',
            'rule_snapshot' => [
                'enabled' => true,
                'conversion_window_days' => 7,
                'min_completed_paid_lessons' => 1,
                'bonus_amount_minor' => 20000,
                'bonus_currency_code' => 'INR',
                'max_awards_per_pair' => 1,
            ],
            'idempotency_key' => 'demo_conversion:'.Str::uuid(),
        ];
    }
}
