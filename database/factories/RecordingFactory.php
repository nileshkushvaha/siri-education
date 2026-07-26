<?php

namespace Database\Factories;

use App\Booking\Enums\RecordingStatus;
use App\Models\Booking;
use App\Models\BookingMeeting;
use App\Models\Recording;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Recording>
 */
class RecordingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'booking_meeting_id' => BookingMeeting::factory(),
            'booking_id' => Booking::factory(),
            'student_id' => User::factory(),
            'teacher_id' => User::factory(),
            'provider' => 'fake',
            'provider_reference' => 'fake-recording-'.Str::uuid(),
            'status' => RecordingStatus::Pending,
            'idempotency_key' => 'recording:'.Str::uuid(),
            'consent_snapshot' => ['student_consented' => true, 'teacher_consented' => true],
        ];
    }

    public function available(): static
    {
        return $this->state([
            'status' => RecordingStatus::Available,
            'duration_seconds' => 1800,
            'size_bytes' => 104857600,
            'mime_type' => 'video/mp4',
            'recorded_at' => now()->subDay(),
            'available_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);
    }

    public function failed(): static
    {
        return $this->state([
            'status' => RecordingStatus::Failed,
            'failure_code' => 'provider_error',
            'failed_at' => now(),
            'capture_attempts' => 5,
        ]);
    }

    public function expired(): static
    {
        return $this->state([
            'status' => RecordingStatus::Expired,
            'duration_seconds' => 1800,
            'size_bytes' => 104857600,
            'mime_type' => 'video/mp4',
            'recorded_at' => now()->subDays(40),
            'available_at' => now()->subDays(39),
            'expires_at' => now()->subDay(),
        ]);
    }
}
