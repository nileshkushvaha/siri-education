<?php

namespace Database\Factories;

use App\Booking\Enums\BookingGuestStatus;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingGuest>
 */
class BookingGuestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'user_id' => null,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'status' => BookingGuestStatus::Invited,
        ];
    }

    public function registered(): static
    {
        return $this->state([
            'user_id' => User::factory(),
            'name' => null,
            'email' => null,
        ]);
    }

    public function confirmed(): static
    {
        return $this->state([
            'status' => BookingGuestStatus::Confirmed,
            'responded_at' => now(),
        ]);
    }
}
