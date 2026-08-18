<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Enums\RecordingStatus;
use App\Booking\Jobs\CaptureLessonRecordingJob;
use App\Booking\Meetings\FakeMeetingProvider;
use App\Booking\Registry\MeetingProviderRegistry;
use App\Booking\Services\RecordingService;
use App\Booking\Services\RecordingStagingArea;
use App\Models\Recording;
use App\Models\User;
use App\Notifications\Booking\RecordingAvailableNotification;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Tests\Support\InMemoryRecordingStorage;
use Tests\TestCase;

/**
 * The idempotency contract, stated as one property:
 *
 *   however many times the same recording is observed — a duplicate
 *   provider event, a redelivered queue message, the reconciliation
 *   sweep, a manual admin retry, two workers at once — the result is
 *   ONE canonical Recording row, ONE stored object, and ONE
 *   notification per participant.
 *
 * Never "recording.mp4, recording-1.mp4, recording-2.mp4".
 *
 * The guarantees under test are all persistent, not in-memory: the
 * unique idempotency_key, the unique (storage_driver, storage_path)
 * locator, and the atomic Pending → Transferring claim under a row
 * lock. Nothing here depends on a process remembering anything.
 */
final class RecordingIdempotencyTest extends TestCase
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
        $this->app->instance(InMemoryRecordingStorage::class, $this->storage);
        config([
            'recordings.storage_driver' => InMemoryRecordingStorage::KEY,
            'recordings.drivers' => [InMemoryRecordingStorage::KEY => InMemoryRecordingStorage::class],
        ]);

        $this->service = app(RecordingService::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(app(RecordingStagingArea::class)->path());

        parent::tearDown();
    }

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

    private function runJob(Recording $recording): void
    {
        (new CaptureLessonRecordingJob($recording->getKey()))->handle(
            app(RecordingService::class),
            app(MeetingProviderRegistry::class),
        );
    }

    /**
     * The full realistic sequence: initial event, a duplicate replay,
     * a redelivered queue message, and the reconciliation sweep — all
     * observing the same recording.
     */
    public function test_event_replay_job_redelivery_and_reconciliation_all_converge_on_one_stored_object(): void
    {
        $recording = $this->pendingRecording();
        FakeMeetingProvider::$nextRecordingContents = $this->fakeMp4Bytes();

        $this->runJob($recording);              // initial dispatch
        $this->runJob($recording->fresh());     // duplicate provider event
        $this->runJob($recording->fresh());     // redelivered queue message
        $this->artisan('recordings:capture');   // reconciliation sweep
        $this->artisan('recordings:capture');   // and again

        $this->assertSame(1, Recording::query()->count(), 'one canonical recording');
        $this->assertCount(1, $this->storage->objects, 'one stored binary');
        $this->assertSame(RecordingStatus::Available, $recording->fresh()->status);
        Notification::assertSentToTimes($recording->fresh()->student, RecordingAvailableNotification::class, 1);
        Notification::assertSentToTimes($recording->fresh()->teacher, RecordingAvailableNotification::class, 1);
    }

    /**
     * Two workers holding the same recording at the same moment: the
     * claim is an atomic status transition under a row lock, so the
     * second worker finds the row already Transferring and exits
     * without fetching or uploading anything.
     */
    public function test_a_second_worker_arriving_mid_transfer_does_not_start_a_second_upload(): void
    {
        $recording = $this->pendingRecording();
        FakeMeetingProvider::$nextRecordingContents = $this->fakeMp4Bytes();

        // Worker A has claimed the row and is uploading right now.
        $recording->forceFill([
            'status' => RecordingStatus::Transferring,
            'transfer_started_at' => now(),
        ])->save();

        // Worker B arrives with a stale copy of the same row.
        $this->service->capture($recording->fresh(), new FakeMeetingProvider);

        $this->assertCount(0, $this->storage->objects, 'the second worker must not upload');
        $this->assertSame(RecordingStatus::Transferring, $recording->fresh()->status);
    }

    public function test_capturing_an_already_available_recording_is_a_no_op(): void
    {
        $recording = $this->pendingRecording();
        FakeMeetingProvider::$nextRecordingContents = $this->fakeMp4Bytes();

        $this->service->capture($recording, new FakeMeetingProvider);
        $recording->refresh();
        $originalPath = $recording->storage_path;
        $originalAvailableAt = $recording->available_at;

        FakeMeetingProvider::$nextRecordingContents = $this->fakeMp4Bytes();
        FakeMeetingProvider::$nextRecordingReference = 'should-not-apply';
        $this->service->capture($recording->fresh(), new FakeMeetingProvider);
        $recording->refresh();

        $this->assertSame($originalPath, $recording->storage_path);
        $this->assertEquals($originalAvailableAt, $recording->available_at);
        $this->assertCount(1, $this->storage->objects);
    }

    /**
     * The database, not application logic, is the final guarantee: two
     * rows can never both claim the same stored object.
     */
    public function test_the_database_rejects_two_recordings_claiming_the_same_storage_locator(): void
    {
        $first = Recording::factory()->available()->create();

        $this->expectException(QueryException::class);

        Recording::factory()->available()->create([
            'storage_driver' => $first->storage_driver,
            'storage_path' => $first->storage_path,
        ]);
    }

    /**
     * An admin retry must not be able to re-run against a recording
     * that already succeeded — that is the one path that could
     * otherwise upload a duplicate on purpose.
     */
    public function test_an_admin_retry_refuses_a_recording_that_is_not_failed(): void
    {
        $recording = $this->pendingRecording();
        FakeMeetingProvider::$nextRecordingContents = $this->fakeMp4Bytes();
        $this->service->capture($recording, new FakeMeetingProvider);

        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->givePermissionTo(
            Permission::firstOrCreate(['name' => 'Retry:Recording', 'guard_name' => 'web'])
        );

        $retried = $this->service->retryFailed($recording->fresh(), $admin);

        $this->assertFalse($retried);
        $this->assertSame(RecordingStatus::Available, $recording->fresh()->status);
        $this->assertCount(1, $this->storage->objects);
    }
}
