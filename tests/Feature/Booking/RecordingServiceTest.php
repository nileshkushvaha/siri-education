<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Alerts\Enums\OperationalAlertType;
use App\Booking\DTOs\RecordingStorageRequest;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\RecordingFailureCode;
use App\Booking\Enums\RecordingStatus;
use App\Booking\Meetings\FakeMeetingProvider;
use App\Booking\Services\RecordingService;
use App\Booking\Services\RecordingStagingArea;
use App\Booking\Storage\FilesystemRecordingStorage;
use App\Enums\InstructorStatus;
use App\Enums\StudentStatus;
use App\Models\Booking;
use App\Models\BookingMeeting;
use App\Models\NotificationDispatchLog;
use App\Models\OperationalAlert;
use App\Models\Recording;
use App\Models\User;
use App\Notifications\Booking\RecordingAvailableNotification;
use App\Settings\FeatureSettings;
use App\Settings\MeetingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\Support\InMemoryRecordingStorage;
use Tests\TestCase;

/**
 * RecordingService is the single authoritative writer for the
 * `recordings` table: idempotent registration/capture, retry and
 * alerting, retention/expiry, and access authorization.
 *
 * Storage is a fake in-memory backend throughout — the service does
 * not know or care which backend is active, which is exactly the
 * property the RecordingStorage abstraction exists to provide.
 */
final class RecordingServiceTest extends TestCase
{
    use RefreshDatabase;

    private RecordingService $service;

    private User $student;

    private User $instructor;

    private InMemoryRecordingStorage $storage;

    protected function tearDown(): void
    {
        File::deleteDirectory(app(RecordingStagingArea::class)->path());

        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        $features = app(FeatureSettings::class);
        $features->recording_enabled = true;
        $features->save();

        $meetings = app(MeetingSettings::class);
        $meetings->recording_enabled = true;
        $meetings->save();

        $this->storage = new InMemoryRecordingStorage;
        $this->app->instance(InMemoryRecordingStorage::class, $this->storage);
        config([
            'recordings.storage_driver' => InMemoryRecordingStorage::KEY,
            'recordings.drivers' => [
                InMemoryRecordingStorage::KEY => InMemoryRecordingStorage::class,
                // Kept resolvable so factory rows written under the
                // filesystem backend still delete correctly — the same
                // situation a real platform is in mid-migration.
                FilesystemRecordingStorage::KEY => FilesystemRecordingStorage::class,
            ],
        ]);

        $this->service = app(RecordingService::class);

        $this->student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->student->assignRole('student');
        $this->student->profile->update(['student_status' => StudentStatus::Active, 'consents_to_recording' => true]);

        $this->instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->instructor->assignRole('instructor');
        $this->instructor->profile->update(['instructor_status' => InstructorStatus::Active, 'consents_to_recording' => true]);

        FakeMeetingProvider::reset();
    }

    /** Minimal bytes finfo genuinely detects as video/mp4 — staging validates the real detected mime, not a caller-supplied hint. */
    private function fakeMp4Bytes(): string
    {
        return "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41".str_repeat("\x00", 200);
    }

    private function confirmedBookingWithMeeting(): array
    {
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Confirmed,
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
        ]);

        $meeting = BookingMeeting::factory()->created()->for($booking)->create([
            'provider' => FakeMeetingProvider::KEY,
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHour(),
        ]);

        return [$booking, $meeting];
    }

    // ── registerIfEligible() idempotency ─────────────────────────────

    public function test_register_if_eligible_creates_exactly_one_pending_recording(): void
    {
        [$booking, $meeting] = $this->confirmedBookingWithMeeting();

        $recording = $this->service->registerIfEligible($booking, $meeting, new FakeMeetingProvider);

        $this->assertNotNull($recording);
        $this->assertSame(RecordingStatus::Pending, $recording->status);
        $this->assertSame(1, Recording::query()->count());
        $this->assertSame('recording:'.$meeting->id, $recording->idempotency_key);
    }

    public function test_register_if_eligible_returns_null_and_creates_nothing_when_ineligible(): void
    {
        [$booking, $meeting] = $this->confirmedBookingWithMeeting();
        $this->student->profile->update(['consents_to_recording' => false]);

        $recording = $this->service->registerIfEligible($booking->fresh(), $meeting, new FakeMeetingProvider);

        $this->assertNull($recording);
        $this->assertSame(0, Recording::query()->count());
    }

    public function test_register_if_eligible_is_idempotent_across_repeated_calls(): void
    {
        [$booking, $meeting] = $this->confirmedBookingWithMeeting();

        $first = $this->service->registerIfEligible($booking, $meeting, new FakeMeetingProvider);
        $second = $this->service->registerIfEligible($booking, $meeting, new FakeMeetingProvider);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Recording::query()->count());
    }

    // ── capture() ─────────────────────────────────────────────────────

    public function test_capture_success_stores_media_and_marks_available(): void
    {
        $recording = Recording::factory()->create([
            'student_id' => $this->student->id,
            'teacher_id' => $this->instructor->id,
        ]);
        // Ensure the related booking_meeting has ended so retry-window math is sane.
        $recording->bookingMeeting->update(['ends_at' => now()->subHour()]);

        $content = $this->fakeMp4Bytes();
        FakeMeetingProvider::$nextRecordingContents = $content;
        FakeMeetingProvider::$nextRecordingReference = 'ref-123';
        FakeMeetingProvider::$nextRecordingDurationSeconds = 1800;

        $this->service->capture($recording, new FakeMeetingProvider);
        $recording->refresh();

        $this->assertSame(RecordingStatus::Available, $recording->status);
        $this->assertSame('ref-123', $recording->provider_reference);
        $this->assertSame(strlen($content), $recording->size_bytes);
        $this->assertSame('video/mp4', $recording->mime_type);
        $this->assertSame(1800, $recording->duration_seconds);
        $this->assertNotNull($recording->available_at);
        $this->assertNotNull($recording->expires_at);
        $this->assertSame(InMemoryRecordingStorage::KEY, $recording->storage_driver);
        $this->assertNotNull($recording->storage_path);
        $this->assertCount(1, $this->storage->objects);

        Notification::assertSentTo($this->student, RecordingAvailableNotification::class);
        Notification::assertSentTo($this->instructor, RecordingAvailableNotification::class);
    }

    public function test_capture_notifies_each_participant_only_once_even_if_run_twice(): void
    {
        $recording = Recording::factory()->create([
            'student_id' => $this->student->id,
            'teacher_id' => $this->instructor->id,
        ]);
        $recording->bookingMeeting->update(['ends_at' => now()->subHour()]);

        FakeMeetingProvider::$nextRecordingContents = $this->fakeMp4Bytes();
        FakeMeetingProvider::$nextRecordingReference = 'ref-abc';
        FakeMeetingProvider::$nextRecordingDurationSeconds = 900;

        $this->service->capture($recording, new FakeMeetingProvider);
        // Second call is a no-op: status already left Pending.
        $this->service->capture($recording->fresh(), new FakeMeetingProvider);

        $this->assertSame(
            2,
            NotificationDispatchLog::query()->where('idempotency_key', 'like', 'recording_available:'.$recording->id.':%')->count(),
        );
        Notification::assertSentToTimes($this->student, RecordingAvailableNotification::class, 1);
        Notification::assertSentToTimes($this->instructor, RecordingAvailableNotification::class, 1);
    }

    public function test_capture_leaves_recording_pending_when_provider_reports_not_ready(): void
    {
        $recording = Recording::factory()->create([
            'student_id' => $this->student->id,
            'teacher_id' => $this->instructor->id,
        ]);
        $recording->bookingMeeting->update(['ends_at' => now()->subMinutes(10)]);

        FakeMeetingProvider::$nextRecordingContents = null;

        $this->service->capture($recording, new FakeMeetingProvider);
        $recording->refresh();

        $this->assertSame(RecordingStatus::Pending, $recording->status);
        $this->assertSame(1, $recording->capture_attempts);
        Notification::assertNotSentTo($this->student, RecordingAvailableNotification::class);
        Notification::assertNotSentTo($this->instructor, RecordingAvailableNotification::class);
    }

    public function test_capture_is_a_no_op_for_a_recording_that_already_settled(): void
    {
        $recording = Recording::factory()->available()->create([
            'student_id' => $this->student->id,
            'teacher_id' => $this->instructor->id,
            'provider_reference' => 'original-reference',
        ]);

        FakeMeetingProvider::$nextRecordingContents = 'ignored';
        FakeMeetingProvider::$nextRecordingReference = 'should-not-apply';
        FakeMeetingProvider::$nextRecordingDurationSeconds = 1;

        $this->service->capture($recording, new FakeMeetingProvider);

        $this->assertSame('original-reference', $recording->fresh()->provider_reference);
        Notification::assertNotSentTo($this->student, RecordingAvailableNotification::class);
        Notification::assertNotSentTo($this->instructor, RecordingAvailableNotification::class);
    }

    public function test_capture_retries_within_the_configured_window_on_transient_failure(): void
    {
        $meetings = app(MeetingSettings::class);
        $meetings->recording_capture_retry_minutes = 1440;
        $meetings->recording_capture_max_attempts = 5;
        $meetings->save();

        $recording = Recording::factory()->create([
            'student_id' => $this->student->id,
            'teacher_id' => $this->instructor->id,
        ]);
        $recording->bookingMeeting->update(['ends_at' => now()->subMinutes(30)]);

        FakeMeetingProvider::$failNextRecordingFetch = true;

        $this->service->capture($recording, new FakeMeetingProvider);
        $recording->refresh();

        $this->assertSame(RecordingStatus::Pending, $recording->status);
        $this->assertSame(1, $recording->capture_attempts);
        $this->assertSame(0, OperationalAlert::query()->count());
    }

    public function test_capture_fails_and_raises_an_operational_alert_after_max_attempts_exhausted(): void
    {
        $meetings = app(MeetingSettings::class);
        $meetings->recording_capture_max_attempts = 1;
        $meetings->save();

        $recording = Recording::factory()->create([
            'student_id' => $this->student->id,
            'teacher_id' => $this->instructor->id,
            'capture_attempts' => 1,
        ]);
        $recording->bookingMeeting->update(['ends_at' => now()->subMinutes(30)]);

        FakeMeetingProvider::$failNextRecordingFetch = true;

        $this->service->capture($recording, new FakeMeetingProvider);
        $recording->refresh();

        $this->assertSame(RecordingStatus::Failed, $recording->status);
        $this->assertSame(RecordingFailureCode::RetriesExhausted, $recording->failure_code);
        $this->assertNotNull($recording->failed_at);

        $alert = OperationalAlert::query()->first();
        $this->assertNotNull($alert);
        $this->assertSame(OperationalAlertType::RecordingCaptureFailed, $alert->type);
        $this->assertSame(Recording::class, $alert->subject_type);
        $this->assertSame($recording->id, $alert->subject_id);
    }

    public function test_capture_fails_immediately_when_provider_capability_is_withdrawn_mid_flight(): void
    {
        $recording = Recording::factory()->create([
            'student_id' => $this->student->id,
            'teacher_id' => $this->instructor->id,
        ]);
        FakeMeetingProvider::$supportsRecording = false;

        $this->service->capture($recording, new FakeMeetingProvider);
        $recording->refresh();

        $this->assertSame(RecordingStatus::Failed, $recording->status);
        $this->assertSame(RecordingFailureCode::ProviderCapabilityMissing, $recording->failure_code);
        $this->assertSame(1, OperationalAlert::query()->count());
    }

    // ── expireDueRecordings() ─────────────────────────────────────────

    public function test_expire_due_recordings_deletes_media_but_preserves_metadata(): void
    {
        $recording = Recording::factory()->available()->create([
            'student_id' => $this->student->id,
            'teacher_id' => $this->instructor->id,
            'expires_at' => now()->subMinute(),
        ]);
        // Give the row a real object in the fake backend to delete.
        $stored = $this->storage->put(new RecordingStorageRequest(
            file: app(RecordingStagingArea::class)->stageContents($this->fakeMp4Bytes(), 'lesson.mp4'),
            displayName: 'lesson-BK-TEST.mp4',
            partitionedAt: CarbonImmutable::now(),
        ));
        $recording->forceFill([
            'storage_driver' => $stored->locator->driver,
            'storage_path' => $stored->locator->path,
        ])->save();

        $expiredCount = $this->service->expireDueRecordings(100);
        $recording->refresh();

        $this->assertSame(1, $expiredCount);
        $this->assertSame(RecordingStatus::Expired, $recording->status);
        $this->assertCount(0, $this->storage->objects);
        $this->assertNull($recording->storage_path);
        // Metadata/audit evidence survives the media deletion.
        $this->assertSame(1800, $recording->duration_seconds);
        $this->assertSame(104857600, $recording->size_bytes);
        $this->assertSame('video/mp4', $recording->mime_type);
    }

    public function test_expire_due_recordings_leaves_not_yet_expired_recordings_untouched(): void
    {
        Recording::factory()->available()->create([
            'student_id' => $this->student->id,
            'teacher_id' => $this->instructor->id,
            'expires_at' => now()->addDay(),
        ]);

        $expiredCount = $this->service->expireDueRecordings(100);

        $this->assertSame(0, $expiredCount);
        $this->assertSame(RecordingStatus::Available, Recording::query()->first()->status);
    }

    public function test_expire_due_recordings_respects_the_batch_size(): void
    {
        Recording::factory()->count(3)->available()->create([
            'student_id' => $this->student->id,
            'teacher_id' => $this->instructor->id,
            'expires_at' => now()->subMinute(),
        ]);

        $expiredCount = $this->service->expireDueRecordings(2);

        $this->assertSame(2, $expiredCount);
        $this->assertSame(1, Recording::query()->where('status', RecordingStatus::Available)->count());
    }

    // ── assertCanAccess() ─────────────────────────────────────────────

    public function test_assert_can_access_allows_the_recordings_own_student_and_instructor(): void
    {
        $recording = Recording::factory()->available()->create([
            'student_id' => $this->student->id,
            'teacher_id' => $this->instructor->id,
        ]);

        $this->service->assertCanAccess($this->student, $recording);
        $this->service->assertCanAccess($this->instructor, $recording);
        $this->addToAssertionCount(2);
    }

    public function test_assert_can_access_denies_an_unrelated_user_without_the_explicit_permission(): void
    {
        $recording = Recording::factory()->available()->create([
            'student_id' => $this->student->id,
            'teacher_id' => $this->instructor->id,
        ]);
        $stranger = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $this->expectException(AuthorizationException::class);
        $this->service->assertCanAccess($stranger, $recording);
    }
}
