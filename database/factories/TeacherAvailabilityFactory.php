<?php

namespace Database\Factories;

use App\Booking\Enums\Weekday;
use App\Models\TeacherAvailability;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeacherAvailability>
 */
class TeacherAvailabilityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'teacher_id' => User::factory(),
            'day_of_week' => fake()->randomElement(Weekday::cases()),
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'is_active' => true,
        ];
    }

    public function forDay(Weekday $day): static
    {
        return $this->state(['day_of_week' => $day]);
    }

    public function between(string $startTime, string $endTime): static
    {
        return $this->state([
            'start_time' => $startTime,
            'end_time' => $endTime,
        ]);
    }
}
