<?php

namespace Database\Factories;

use App\Models\BookingType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingType>
 */
class BookingTypeFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'key' => str($name)->snake()->toString(),
            'name' => ucfirst($name),
            'description' => fake()->optional()->sentence(),
            'duration_minutes' => fake()->randomElement([30, 45, 60, 90]),
            'max_attendees' => 1,
            'requires_approval' => false,
            'is_paid' => false,
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 20),
        ];
    }

    public function paid(float $price = 49.99, string $currency = 'USD'): static
    {
        return $this->state([
            'is_paid' => true,
            'price' => $price,
            'currency' => $currency,
        ]);
    }

    public function requiresApproval(): static
    {
        return $this->state(['requires_approval' => true]);
    }

    public function withBuffer(int $minutes = 15): static
    {
        return $this->state(['buffer_minutes' => $minutes]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
