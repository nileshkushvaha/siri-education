<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Enums\RecordingFailureCode;
use App\Booking\Enums\RecordingStatus;
use App\Booking\Exceptions\RecordingStorageException;
use App\Booking\Meetings\FakeMeetingProvider;
use App\Booking\Services\RecordingService;
use App\Booking\Services\RecordingStagingArea;
use App\Models\Recording;
use App\Settings\MeetingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Tests\Support\InMemoryRecordingStorage;
use Tests\TestCase;

/**
 * The provider → storage transfer, exercised entirely against a fake
 * in-memory RecordingStorage. Nothing in this file imports a Google
 * type: that is the point — the ingestion pipeline is written against
 * the abstraction, so the same tests will hold when the backend
 * becomes S3.
 */
final class RecordingIngestionTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryRecordingStorage $storage;

    private RecordingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        FakeMeetingProvider::reset();

        $this->storage = new InMemoryRecordingStorage;
        $this->useFakeStorage($this->storage);

        $this->service = app(RecordingService::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(app(RecordingStagingArea::class)->path());

        parent::tearDown();
    }

    /** Substitutes the fake backend as both the default and a resolvable driver. */
    private function useFakeStorage(InMemoryRecordingStorage $storage): void
    {
        $this->app->instance(InMemoryRecordingStorage::class, $storage);
        config([
            'recordings.storage_driver' => InMemoryRecordingStorage::KEY,
            'recordings.drivers' => [InMemoryRecordingStorage::KEY => InMemoryRecordingStorage::class],
        ]);
    }

    /** Minimal bytes finfo genuinely detects as video/mp4 — staging validates real detected mime, not a caller's hint. */
    private function fakeMp4Bytes(): string
    {
        return "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41".str_repeat("\x00", 200);
    }

    private function pendingRecording(): Recording
    {
        $recording = Recording::factory()->create(['provider' => FakeMeetingProvider::KEY]);
        $recording->bookingMeeting->update(['ends_at' => now()->subHour(), 'provider' => FakeMeetingProvider::KEY]);

        return $recording->fresh();
    }

    private function stagedFileCount(): int
    {
        return count(File::files(app(RecordingStagingArea::class)->path()));
    }

    // ── Happy path ────────────────────────────────────────────────────

    public function test_successful_ingestion_stores_verifies_and_publishes_the_recording(): void
    {
        $recording = $this->pendingRecording();
        $bytes = $this->fakeMp4Bytes();
        FakeMeetingProvider::$nextRecordingContents = $bytes;

        $this->service->capture($recording, new FakeMeetingProvider);
        $recording->refresh();

        $this->assertSame(RecordingStatus::Available, $recording->status);
        $this->assertSame(InMemoryRecordingStorage::KEY, $recording->storage_driver);
        $this->assertNotNull($recording->storage_path);
        $this->assertSame(strlen($bytes), $recording->size_bytes);
        $this->assertSame('video/mp4', $recording->mime_type);
        $this->assertSame(hash('sha256', $bytes), $recording->storage_checksum);
        $this->assertNotNull($recording->stored_at);
        $this->assertNotNull($recording->available_at);
        $this->assertNotNull($recording->expires_at);
        $this->assertCount(1, $this->storage->objects);
    }

    /**
     * The exact bytes matter: a pipeline that stored a truncated or
     * re-encoded file while still reporting Available would be worse
     * than one that failed outright.
     */
    public function test_the_stored_object_is_byte_identical_to_what_the_provider_supplied(): void
    {
        $recording = $this->pendingRecording();
        $bytes = $this->fakeMp4Bytes();
        FakeMeetingProvider::$nextRecordingContents = $bytes;

        $this->service->capture($recording, new FakeMeetingProvider);

        $this->assertSame($bytes, array_values($this->storage->objects)[0]['bytes']);
    }

    public function test_the_stored_filename_carries_the_booking_reference_and_no_participant_pii(): void
    {
        $recording = $this->pendingRecording();
        FakeMeetingProvider::$nextRecordingContents = $this->fakeMp4Bytes();

        $this->service->capture($recording, new FakeMeetingProvider);
        $recording->refresh();

        $name = $this->storage->storedName($recording->storage_path);

        $this->assertStringContainsString($recording->booking->reference, $name);
        $this->assertStringEndsWith('.mp4', $name);
        $this->assertStringNotContainsString($recording->student->email, $name);
        $this->assertStringNotContainsString($recording->student->name, $name);
        $this->assertStringNotContainsString($recording->teacher->name, $name);
    }

    // ── Verification gate ─────────────────────────────────────────────

    /**
     * A backend that accepted the upload but holds something other
     * than what we sent must NOT produce an Available recording — the
     * whole reason Stored and Available are separate states.
     */
    public function test_a_size_mismatch_at_the_backend_prevents_the_recording_becoming_available(): void
    {
        $recording = $this->pendingRecording();
        FakeMeetingProvider::$nextRecordingContents = $this->fakeMp4Bytes();
        $this->storage->reportWrongSize = true;

        $this->service->capture($recording, new FakeMeetingProvider);
        $recording->refresh();

        $this->assertNotSame(RecordingStatus::Available, $recording->status);
        $this->assertNull($recording->available_at);
        Notification::assertNothingSent();
    }

    /**
     * Interrupted after upload, before verification: the retry must
     * re-verify the object already there, never upload a second copy.
     */
    public function test_a_recording_interrupted_after_upload_resumes_at_verification_without_reuploading(): void
    {
        $recording = $this->pendingRecording();
        FakeMeetingProvider::$nextRecordingContents = $this->fakeMp4Bytes();
        $this->storage->failNextVerify = RecordingStorageException::verificationFailed('transient backend blip');

        $this->service->capture($recording, new FakeMeetingProvider);
        $this->assertSame(RecordingStatus::Stored, $recording->fresh()->status);
        $this->assertCount(1, $this->storage->objects);

        // The retry: still one object, now published.
        $this->service->capture($recording->fresh(), new FakeMeetingProvider);

        $this->assertSame(RecordingStatus::Available, $recording->fresh()->status);
        $this->assertCount(1, $this->storage->objects, 'a resumed ingestion must not upload a second copy');
    }

    // ── Failure classification ────────────────────────────────────────

    public function test_a_permanent_storage_failure_stops_retrying_immediately(): void
    {
        $meetings = app(MeetingSettings::class);
        $meetings->recording_capture_max_attempts = 5;
        $meetings->recording_capture_retry_minutes = 1440;
        $meetings->save();

        $recording = $this->pendingRecording();
        FakeMeetingProvider::$nextRecordingContents = $this->fakeMp4Bytes();
        $this->storage->failNextPut = RecordingStorageException::notConfigured('in_memory');

        $this->service->capture($recording, new FakeMeetingProvider);
        $recording->refresh();

        // Permanent: failed on attempt 1 despite a wide-open budget.
        $this->assertSame(RecordingStatus::Failed, $recording->status);
        $this->assertSame(RecordingFailureCode::StorageNotConfigured, $recording->failure_code);
        $this->assertSame(1, $recording->capture_attempts);
    }

    public function test_a_transient_storage_failure_stays_retryable_inside_the_window(): void
    {
        $meetings = app(MeetingSettings::class);
        $meetings->recording_capture_max_attempts = 5;
        $meetings->recording_capture_retry_minutes = 1440;
        $meetings->save();

        $recording = $this->pendingRecording();
        FakeMeetingProvider::$nextRecordingContents = $this->fakeMp4Bytes();
        $this->storage->failNextPut = RecordingStorageException::quotaExceeded('drive is full');

        $this->service->capture($recording, new FakeMeetingProvider);
        $recording->refresh();

        $this->assertSame(RecordingStatus::Pending, $recording->status);
        $this->assertNull($recording->failure_code);
        $this->assertSame(1, $recording->capture_attempts);
    }

    /**
     * A recording failure is a recording failure. It must never reach
     * back into lesson, booking, payment or earnings state.
     */
    public function test_a_failed_ingestion_leaves_the_booking_and_meeting_untouched(): void
    {
        $recording = $this->pendingRecording();
        $bookingSnapshot = $recording->booking->only(['status', 'payment_status']);
        $meetingSnapshot = $recording->bookingMeeting->only(['status']);

        FakeMeetingProvider::$nextRecordingContents = $this->fakeMp4Bytes();
        $this->storage->failNextPut = RecordingStorageException::notConfigured('in_memory');

        $this->service->capture($recording, new FakeMeetingProvider);

        $this->assertSame(RecordingStatus::Failed, $recording->fresh()->status);
        $this->assertSame($bookingSnapshot, $recording->booking->fresh()->only(['status', 'payment_status']));
        $this->assertSame($meetingSnapshot, $recording->bookingMeeting->fresh()->only(['status']));
    }

    // ── Temporary file hygiene ────────────────────────────────────────

    public function test_a_successful_transfer_leaves_no_staged_file_behind(): void
    {
        $recording = $this->pendingRecording();
        FakeMeetingProvider::$nextRecordingContents = $this->fakeMp4Bytes();

        $this->service->capture($recording, new FakeMeetingProvider);

        $this->assertSame(0, $this->stagedFileCount());
    }

    public function test_a_failed_transfer_also_leaves_no_staged_file_behind(): void
    {
        $recording = $this->pendingRecording();
        FakeMeetingProvider::$nextRecordingContents = $this->fakeMp4Bytes();
        $this->storage->failNextPut = RecordingStorageException::uploadFailed('backend exploded');

        $this->service->capture($recording, new FakeMeetingProvider);

        $this->assertSame(0, $this->stagedFileCount(), 'staged video must be deleted even when the upload fails');
    }

    public function test_the_sweep_purges_staged_files_a_crashed_run_left_behind(): void
    {
        $staging = app(RecordingStagingArea::class);
        $orphan = $staging->path('orphaned-'.uniqid().'.mp4');
        file_put_contents($orphan, 'left over by a killed worker');
        touch($orphan, now()->subDays(3)->getTimestamp());

        $this->artisan('recordings:capture')->assertSuccessful();

        $this->assertFileDoesNotExist($orphan);
    }

    // ── Safety limits ─────────────────────────────────────────────────

    public function test_a_source_larger_than_the_configured_ceiling_is_rejected_permanently(): void
    {
        config(['recordings.max_source_bytes' => 32]);

        $recording = $this->pendingRecording();
        FakeMeetingProvider::$nextRecordingContents = $this->fakeMp4Bytes();

        $this->service->capture($recording, new FakeMeetingProvider);
        $recording->refresh();

        $this->assertSame(RecordingStatus::Failed, $recording->status);
        $this->assertSame(RecordingFailureCode::SourceRejected, $recording->failure_code);
        $this->assertCount(0, $this->storage->objects, 'an oversized source must never reach storage');
    }

    public function test_a_source_that_is_not_an_accepted_recording_format_is_rejected(): void
    {
        $recording = $this->pendingRecording();
        FakeMeetingProvider::$nextRecordingContents = 'this is plain text, not a lesson recording';

        $this->service->capture($recording, new FakeMeetingProvider);
        $recording->refresh();

        $this->assertSame(RecordingStatus::Failed, $recording->status);
        $this->assertSame(RecordingFailureCode::SourceRejected, $recording->failure_code);
        $this->assertCount(0, $this->storage->objects);
    }

    // ── Stalled transfer reclaim ──────────────────────────────────────

    public function test_the_sweep_returns_a_recording_abandoned_mid_transfer_to_pending(): void
    {
        $meetings = app(MeetingSettings::class);
        $meetings->recording_transfer_stale_minutes = 60;
        $meetings->save();

        $recording = Recording::factory()->transferring()->create(['provider' => FakeMeetingProvider::KEY]);
        $recording->bookingMeeting->update(['ends_at' => now()->subHours(8), 'provider' => FakeMeetingProvider::KEY]);

        $this->artisan('recordings:capture')->assertSuccessful();

        // Reclaimed to Pending, then attempted in the same sweep and
        // left Pending again because the fake provider has nothing ready.
        $this->assertSame(RecordingStatus::Pending, $recording->fresh()->status);
    }

    public function test_a_transfer_still_inside_the_stale_window_is_not_reclaimed(): void
    {
        $meetings = app(MeetingSettings::class);
        $meetings->recording_transfer_stale_minutes = 600;
        $meetings->save();

        $recording = Recording::factory()->transferring()->create(['provider' => FakeMeetingProvider::KEY]);

        $this->artisan('recordings:capture')->assertSuccessful();

        $this->assertSame(RecordingStatus::Transferring, $recording->fresh()->status);
    }

    // ── Retention ─────────────────────────────────────────────────────

    public function test_expiry_deletes_the_stored_object_and_keeps_the_metadata(): void
    {
        $recording = $this->pendingRecording();
        FakeMeetingProvider::$nextRecordingContents = $this->fakeMp4Bytes();
        $this->service->capture($recording, new FakeMeetingProvider);

        $recording->refresh();
        $objectId = $recording->storage_path;
        $recording->forceFill(['expires_at' => now()->subMinute()])->save();

        $expired = $this->service->expireDueRecordings(10);
        $recording->refresh();

        $this->assertSame(1, $expired);
        $this->assertSame(RecordingStatus::Expired, $recording->status);
        $this->assertContains($objectId, $this->storage->deleted);
        $this->assertCount(0, $this->storage->objects);
        // Metadata outlives the file (SRS §12.21).
        $this->assertNotNull($recording->size_bytes);
        $this->assertNotNull($recording->duration_seconds);
        $this->assertSame('video/mp4', $recording->mime_type);
        // The locator is cleared; the backend is kept as evidence.
        $this->assertNull($recording->storage_path);
        $this->assertSame(InMemoryRecordingStorage::KEY, $recording->storage_driver);
    }

    /**
     * Deleting the row first and discovering afterwards that the object
     * survived would leave an untracked recording in storage forever.
     */
    public function test_a_failed_object_deletion_leaves_the_recording_available_for_the_next_sweep(): void
    {
        $recording = $this->pendingRecording();
        FakeMeetingProvider::$nextRecordingContents = $this->fakeMp4Bytes();
        $this->service->capture($recording, new FakeMeetingProvider);

        $recording->refresh();
        $recording->forceFill(['expires_at' => now()->subMinute()])->save();

        // A backend that cannot delete right now.
        $this->storage->failDelete = RecordingStorageException::uploadFailed('backend unreachable');

        $expired = $this->service->expireDueRecordings(10);

        $this->assertSame(0, $expired);
        $this->assertSame(RecordingStatus::Available, $recording->fresh()->status);
        $this->assertNotNull($recording->fresh()->storage_path);
    }

    // ── Notification ──────────────────────────────────────────────────

    public function test_participants_are_notified_only_once_the_recording_is_verified(): void
    {
        $recording = $this->pendingRecording();
        FakeMeetingProvider::$nextRecordingContents = $this->fakeMp4Bytes();
        $this->storage->failNextVerify = RecordingStorageException::verificationFailed('not yet');

        $this->service->capture($recording, new FakeMeetingProvider);
        Notification::assertNothingSent();

        $this->service->capture($recording->fresh(), new FakeMeetingProvider);

    }
}
