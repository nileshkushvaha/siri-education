<?php

namespace Database\Factories;

use App\Booking\Enums\MeetingStatus;
use App\Models\Booking;
use App\Models\BookingMeeting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingMeeting>
 */
class BookingMeetingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'provider' => 'manual',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'timezone' => 'UTC',
            'status' => MeetingStatus::Pending,
        ];
    }

    public function created(string $joinUrl = 'https://meet.example.test/abc'): static
    {
        return $this->state([
            'status' => MeetingStatus::Created,
            'join_url' => $joinUrl,
        ]);
    }

    public function failed(string $reason = 'Provider error'): static
    {
        return $this->state([
            'status' => MeetingStatus::Failed,
            'failure_reason' => $reason,
        ]);
    }

    public function google(): static
    {
        return $this->state(['provider' => 'google_meet']);
    }

    public function zoom(): static
    {
        return $this->state(['provider' => 'zoom']);
    }
}
