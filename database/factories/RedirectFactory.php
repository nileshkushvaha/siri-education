<?php

namespace Database\Factories;

use App\Content\Redirects\Enums\RedirectType;
use App\Models\Redirect;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Redirect>
 */
class RedirectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'source_path' => '/'.fake()->unique()->slug(3),
            'target_path' => '/'.fake()->slug(3),
            'type' => RedirectType::Permanent,
            'is_active' => true,
            'description' => fake()->optional()->sentence(),
            'created_by' => User::factory(),
        ];
    }

    public function temporary(): static
    {
        return $this->state(['type' => RedirectType::Temporary]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
