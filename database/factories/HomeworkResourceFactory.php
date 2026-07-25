<?php

namespace Database\Factories;

use App\Homework\Enums\HomeworkResourceStatus;
use App\Models\HomeworkResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HomeworkResource>
 */
class HomeworkResourceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'instructor_id' => User::factory(),
            'title' => ucfirst(fake()->words(3, true)),
            'description' => fake()->optional()->sentence(12),
            'status' => HomeworkResourceStatus::Active,
        ];
    }

    public function archived(): static
    {
        return $this->state(['status' => HomeworkResourceStatus::Archived]);
    }
}
