<?php

namespace Database\Factories;

use App\Models\TeacherUnavailability;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<TeacherUnavailability>
 */
class TeacherUnavailabilityFactory extends Factory
{
    public function definition(): array
    {
        $startsAt = Carbon::instance(fake()->dateTimeBetween('+1 day', '+30 days'))->startOfDay();

        return [
            'teacher_id' => User::factory(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addDay(),
            'timezone' => 'UTC',
            'reason' => fake()->optional()->sentence(3),
        ];
    }
}
