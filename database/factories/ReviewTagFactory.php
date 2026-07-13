<?php

namespace Database\Factories;

use App\Models\ReviewTag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReviewTag>
 */
class ReviewTagFactory extends Factory
{
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2, false),
            'label' => fake()->words(2, true),
            'applicable_modes' => ['public_review', 'private_feedback'],
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function publicOnly(): static
    {
        return $this->state(['applicable_modes' => ['public_review']]);
    }

    public function privateOnly(): static
    {
        return $this->state(['applicable_modes' => ['private_feedback']]);
    }
}
