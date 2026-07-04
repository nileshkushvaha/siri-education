<?php

namespace Database\Factories;

use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingLocationType;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    public function definition(): array
    {
        $startsAt = Carbon::instance(fake()->dateTimeBetween('+1 day', '+30 days'))->startOfHour();

        return [
            'booking_type_id' => BookingType::factory(),
            'attendee_id' => User::factory(),
            'host_id' => User::factory(),
            'status' => BookingStatus::Pending,
            'payment_status' => BookingPaymentStatus::NotRequired,
            'location_type' => BookingLocationType::Online,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(60),
            'timezone' => 'UTC',
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function confirmed(): static
    {
        return $this->state([
            'status' => BookingStatus::Confirmed,
            'confirmed_at' => now(),
        ]);
    }

    public function completed(): static
    {
        $startsAt = Carbon::instance(fake()->dateTimeBetween('-30 days', '-1 day'))->startOfHour();

        return $this->state([
            'status' => BookingStatus::Completed,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(60),
            'confirmed_at' => $startsAt->copy()->subDay(),
            'completed_at' => $startsAt->copy()->addMinutes(60),
        ]);
    }

    public function cancelled(BookingActor $by = BookingActor::Attendee): static
    {
        return $this->state([
            'status' => BookingStatus::Cancelled,
            'cancelled_by' => $by,
            'cancellation_reason' => fake()->sentence(),
            'cancelled_at' => now(),
        ]);
    }

    public function paid(float $price = 49.99, string $currency = 'USD'): static
    {
        return $this->state([
            'payment_status' => BookingPaymentStatus::Paid,
            'price' => $price,
            'currency' => $currency,
            'payment_reference' => 'PAY-'.strtoupper(fake()->bothify('##??####')),
        ]);
    }

    public function withMeeting(string $provider = 'zoom'): static
    {
        return $this->state([
            'meeting_provider' => $provider,
            'meeting_ref' => fake()->uuid(),
            'meeting_url' => fake()->url(),
        ]);
    }
}
