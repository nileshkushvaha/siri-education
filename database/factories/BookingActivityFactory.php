<?php

namespace Database\Factories;

use App\Booking\Enums\BookingActivityAction;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingActivity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingActivity>
 */
class BookingActivityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'action' => BookingActivityAction::Requested,
            'actor_type' => BookingActor::Student,
            'status_from' => null,
            'status_to' => BookingStatus::Pending,
            'created_at' => now(),
        ];
    }

    public function transition(BookingStatus $from, BookingStatus $to, BookingActivityAction $action): static
    {
        return $this->state([
            'action' => $action,
            'status_from' => $from,
            'status_to' => $to,
        ]);
    }
}
