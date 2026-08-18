<?php

namespace Database\Factories;

use App\Booking\Enums\RecordingFailureCode;
use App\Booking\Enums\RecordingStatus;
use App\Booking\Storage\FilesystemRecordingStorage;
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

    /**
     * A verified, serveable recording. Defaults to the filesystem
     * backend because that is what Storage::fake() gives a test — a
     * factory must never require Google credentials to build a row.
     */
    public function available(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RecordingStatus::Available,
            'storage_driver' => FilesystemRecordingStorage::KEY,
            'storage_path' => 'recordings/2026/08/lesson-'.Str::random(8).'.mp4',
            'storage_checksum' => hash('sha256', Str::random(16)),
            'duration_seconds' => 1800,
            'size_bytes' => 104857600,
            'mime_type' => 'video/mp4',
            'recorded_at' => now()->subDay(),
            'stored_at' => now(),
            'available_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);
    }

    /** Uploaded but not yet verified — the resume-at-verification path. */
    public function stored(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RecordingStatus::Stored,
            'storage_driver' => FilesystemRecordingStorage::KEY,
            'storage_path' => 'recordings/2026/08/lesson-'.Str::random(8).'.mp4',
            'storage_checksum' => hash('sha256', Str::random(16)),
            'size_bytes' => 1024,
            'mime_type' => 'video/mp4',
            'recorded_at' => now()->subHour(),
            'stored_at' => now(),
        ]);
    }

    /** Claimed by a worker that never finished. */
    public function transferring(): static
    {
        return $this->state([
            'status' => RecordingStatus::Transferring,
            'transfer_started_at' => now()->subHours(6),
            'capture_attempts' => 1,
        ]);
    }

    public function failed(): static
    {
        return $this->state([
            'status' => RecordingStatus::Failed,
            'failure_code' => RecordingFailureCode::RetriesExhausted,
            'failed_at' => now(),
            'capture_attempts' => 5,
        ]);
    }

    /** Retention elapsed: the object is gone, the metadata survives. */
    public function expired(): static
    {
        return $this->state([
            'status' => RecordingStatus::Expired,
            'storage_driver' => FilesystemRecordingStorage::KEY,
            'storage_path' => null,
            'duration_seconds' => 1800,
            'size_bytes' => 104857600,
            'mime_type' => 'video/mp4',
            'recorded_at' => now()->subDays(40),
            'available_at' => now()->subDays(39),
            'expires_at' => now()->subDay(),
        ]);
    }
}
