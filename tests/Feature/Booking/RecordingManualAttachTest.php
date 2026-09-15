<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\RecordingFailureCode;
use App\Booking\Enums\RecordingPlaybackState;
use App\Booking\Enums\RecordingStatus;
use App\Booking\Exceptions\RecordingStorageException;
use App\Booking\Jobs\AttachExternalRecordingJob;
use App\Booking\Services\RecordingPlaybackAccessResolver;
use App\Booking\Services\RecordingService;
use App\Booking\Storage\GoogleDriveRecordingStorage;
use App\Enums\StudentStatus;
use App\Filament\Resources\Bookings\Pages\EditBooking;
use App\Filament\Resources\Recordings\Pages\ListRecordings;
use App\Lessons\Enums\LessonOutcome;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\BookingMeeting;
use App\Models\Lesson;
use App\Models\Recording;
use App\Models\User;
use App\Settings\FeatureSettings;
use App\Settings\MeetingSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\InMemoryRecordingStorage;
use Tests\TestCase;

/**
 * Manual recovery: an administrator attaches a recording file by hand
 * and it reaches the student through the ordinary pipeline — copied,
 * verified, published — with every safety property the pipeline has.
 */
final class RecordingManualAttachTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryRecordingStorage $storage;

    private RecordingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        // The test queue is synchronous; faking it keeps attach and the
        // copy job observable as two separate steps, as in production.
        Queue::fake();

        $this->storage = new InMemoryRecordingStorage;
        $this->app->instance(InMemoryRecordingStorage::class, $this->storage);
        config([
            'recordings.storage_driver' => InMemoryRecordingStorage::KEY,
            'recordings.drivers' => [InMemoryRecordingStorage::KEY => InMemoryRecordingStorage::class],
        ]);
        $this->storage->externalObjects['ext-visible'] = ['bytes' => str_repeat('v', 4096), 'mimeType' => 'video/mp4'];
        $this->storage->externalObjects['ext-trashed'] = ['bytes' => 'x', 'mimeType' => 'video/mp4', 'trashed' => true];

        $this->service = app(RecordingService::class);
    }

    private function admin(string ...$permissions): User
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']));

        foreach ($permissions as $permission) {
            $admin->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        return $admin;
    }

    private function attacher(): User
    {
        return $this->admin('ViewAny:Recording', 'View:Recording', 'Attach:Recording');
    }

    // ── Service ──────────────────────────────────────────────────────

    public function test_attaching_requires_the_permission(): void
    {
        $recording = Recording::factory()->failed()->create();

        $this->expectException(AuthorizationException::class);
        $this->service->attachExternal($recording, $this->admin('ViewAny:Recording', 'View:Recording'), 'ext-visible', 'Instructor sent the file');
    }

    public function test_attaching_validates_the_reference_before_queueing_anything(): void
    {
        $recording = Recording::factory()->failed()->create();

        try {
            $this->service->attachExternal($recording, $this->attacher(), 'ext-nowhere', 'Instructor sent the file');
            $this->fail('An invisible object must be refused at the click.');
        } catch (RecordingStorageException $e) {
            $this->assertSame(RecordingFailureCode::ExternalSourceInaccessible, $e->failureCode);
        }

        Queue::assertNotPushed(AttachExternalRecordingJob::class);
        $this->assertSame(RecordingStatus::Failed, $recording->fresh()->status, 'the row is untouched');
        $this->assertDatabaseMissing('activity_log', ['event' => 'recording_manually_attached']);
    }

    public function test_attaching_marks_the_row_manual_audits_the_override_and_queues_the_copy(): void
    {
        $recording = Recording::factory()->failed()->create(['failure_code' => RecordingFailureCode::SourceNotFound]);
        $admin = $this->attacher();

        $this->service->attachExternal($recording, $admin, 'ext-visible', 'Instructor recorded locally and shared the file');

        $fresh = $recording->fresh();
        $this->assertSame(RecordingStatus::Pending, $fresh->status);
        $this->assertTrue($fresh->isManuallyAttached());
        $this->assertNull($fresh->failure_code);
        $this->assertSame(0, $fresh->capture_attempts);

        $activity = Activity::query()->where('event', 'recording_manually_attached')->firstOrFail();
        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame('source_not_found', $activity->properties['previous_failure_code'] ?? null);
        $this->assertStringContainsString('Instructor recorded locally', json_encode($activity->properties));
        $this->assertStringNotContainsString('ext-visible', json_encode($activity->properties), 'the reference is never written to the audit trail');

        Queue::assertPushed(AttachExternalRecordingJob::class, fn (AttachExternalRecordingJob $job): bool => $job->recordingId === $recording->getKey() && $job->operatorReference === 'ext-visible');
    }

    public function test_a_stored_or_available_recording_and_a_preserved_object_are_never_overwritten(): void
    {
        $admin = $this->attacher();

        foreach ([Recording::factory()->available()->create(), Recording::factory()->stored()->create()] as $recording) {
            try {
                $this->service->attachExternal($recording, $admin, 'ext-visible', 'trying to overwrite');
                $this->fail('A recording with an object must refuse an attach.');
            } catch (InvalidArgumentException $e) {
                $this->assertNotNull($this->service->attachRefusalReason($recording));
            }
        }

        $preserved = Recording::factory()->failed()->create([
            'failure_code' => RecordingFailureCode::MeetingReplacedDuringCapture,
            'storage_driver' => InMemoryRecordingStorage::KEY,
            'storage_path' => 'obj-preserved',
        ]);
        $this->assertStringContainsString('already points at a stored object', (string) $this->service->attachRefusalReason($preserved));

        Queue::assertNotPushed(AttachExternalRecordingJob::class);
    }

    public function test_a_reason_is_mandatory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->attachExternal(Recording::factory()->failed()->create(), $this->attacher(), 'ext-visible', '   ');
    }

    // ── Ingestion ────────────────────────────────────────────────────

    public function test_the_job_copies_verifies_and_publishes_without_touching_the_original(): void
    {
        $recording = Recording::factory()->failed()->create();
        $this->service->attachExternal($recording, $this->attacher(), 'ext-visible', 'Instructor sent the file');

        (new AttachExternalRecordingJob($recording->getKey(), 'ext-visible'))->handle($this->service);

        $fresh = $recording->fresh();
        $this->assertSame(RecordingStatus::Available, $fresh->status);
        $this->assertTrue($fresh->isPlayable());
        $this->assertSame(4096, $fresh->size_bytes);
        $this->assertSame('video/mp4', $fresh->mime_type);
        $this->assertNotNull($fresh->available_at);
        $this->assertArrayHasKey('ext-visible', $this->storage->externalObjects, 'the operator original is never disposed of');
        $this->assertSame([], $this->storage->deleted);
        $this->assertCount(1, $this->storage->objects, 'exactly one copy in the platform area');
        $this->assertDatabaseHas('activity_log', ['event' => 'recording_available', 'subject_id' => $recording->getKey()]);
    }

    public function test_an_object_that_became_unreadable_fails_the_row_cleanly(): void
    {
        $recording = Recording::factory()->failed()->create();
        $this->service->attachExternal($recording, $this->attacher(), 'ext-visible', 'Instructor sent the file');
        unset($this->storage->externalObjects['ext-visible']);

        (new AttachExternalRecordingJob($recording->getKey(), 'ext-visible'))->handle($this->service);

        $fresh = $recording->fresh();
        $this->assertSame(RecordingStatus::Failed, $fresh->status);
        $this->assertSame(RecordingFailureCode::ExternalSourceInaccessible, $fresh->failure_code);
        $this->assertNull($fresh->storage_path);
        $this->assertNull($this->service->attachRefusalReason($fresh), 'the operator can attach again after fixing the sharing');
    }

    public function test_a_trashed_object_is_refused_as_unsupported(): void
    {
        try {
            $this->service->attachExternal(Recording::factory()->failed()->create(), $this->attacher(), 'ext-trashed', 'Instructor sent the file');
            $this->fail('trash must be refused');
        } catch (RecordingStorageException $e) {
            $this->assertSame(RecordingFailureCode::ExternalSourceUnsupported, $e->failureCode);
        }
    }

    // ── No recording record yet ──────────────────────────────────────

    public function test_a_lesson_without_a_recording_record_can_be_registered_and_attached_under_an_override(): void
    {
        $admin = $this->attacher();
        $meeting = BookingMeeting::factory()->create();
        $booking = $meeting->booking;
        $booking->forceFill(['status' => BookingStatus::Completed])->save();
        $this->assertNull($booking->fresh()->recording);

        $recording = $this->service->registerManual($booking->fresh(), $admin);
        $this->service->attachExternal($recording, $admin, 'ext-visible', 'Recorded outside the pipeline', registeredByOverride: true);

        $this->assertTrue($recording->isManuallyAttached());
        $this->assertSame('recording:manual:'.$meeting->id, $recording->idempotency_key);
        $this->assertTrue($recording->consent_snapshot['registered_by_override']);
        $this->assertSame($recording->getKey(), $this->service->registerManual($booking->fresh(), $admin)->getKey(), 'idempotent per meeting');
        $activity = Activity::query()->where('event', 'recording_manually_attached')->firstOrFail();
        $this->assertTrue($activity->properties['registered_by_override']);
        Queue::assertPushed(AttachExternalRecordingJob::class);
    }

    public function test_registering_refuses_a_lesson_without_a_meeting(): void
    {
        $booking = Booking::factory()->confirmed()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->service->registerManual($booking, $this->attacher());
    }

    // ── Student playback ─────────────────────────────────────────────

    public function test_the_student_sees_a_manually_attached_recording_once_the_lesson_is_completed(): void
    {
        app(FeatureSettings::class)->recording_enabled = true;
        app(FeatureSettings::class)->save();
        $settings = app(MeetingSettings::class);
        $settings->recording_enabled = true;
        $settings->recording_student_playback_enabled = true;
        $settings->save();

        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole(Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']));
        $student->profile()->update(['student_status' => StudentStatus::Active]);
        $booking = Booking::factory()->completed()->create(['student_id' => $student->id]);
        $recording = Recording::factory()->failed()->create(['booking_id' => $booking->id, 'student_id' => $student->id]);
        $this->service->attachExternal($recording, $this->attacher(), 'ext-visible', 'Instructor sent the file');
        (new AttachExternalRecordingJob($recording->getKey(), 'ext-visible'))->handle($this->service);
        $this->assertSame(RecordingStatus::Available, $recording->fresh()->status);

        $resolver = app(RecordingPlaybackAccessResolver::class);
        $this->assertSame(RecordingPlaybackState::Hidden, $resolver->stateFor($booking->fresh()->loadMissing('recording', 'lesson'), $student), 'not until the lesson is finalised');

        Lesson::factory()->completed()->withOutcome(LessonOutcome::Completed)->create([
            'booking_id' => $booking->id,
            'student_id' => $booking->student_id,
            'instructor_id' => $booking->instructor_id,
            'starts_at' => $booking->starts_at,
            'ends_at' => $booking->ends_at,
        ]);

        $this->assertSame(RecordingPlaybackState::Available, $resolver->stateFor($booking->fresh()->loadMissing('recording', 'lesson'), $student->fresh()));
    }

    // ── Admin surfaces ───────────────────────────────────────────────

    public function test_the_admin_action_attaches_from_the_recordings_list_and_is_hidden_without_the_permission(): void
    {
        $recording = Recording::factory()->failed()->create();

        Livewire::actingAs($this->attacher())
            ->test(ListRecordings::class)
            ->assertTableActionVisible('attachExternal', $recording)
            ->callTableAction('attachExternal', $recording, ['reference' => 'ext-visible', 'reason' => 'Instructor sent the file'])
            ->assertNotified('Attach queued');

        $this->assertTrue($recording->fresh()->isManuallyAttached());
        Queue::assertPushed(AttachExternalRecordingJob::class);

        Livewire::actingAs($this->admin('ViewAny:Recording', 'View:Recording'))
            ->test(ListRecordings::class)
            ->assertTableActionHidden('attachExternal', Recording::factory()->failed()->create());
    }

    public function test_the_admin_action_shows_the_storage_refusal_instead_of_queueing(): void
    {
        $recording = Recording::factory()->failed()->create();

        Livewire::actingAs($this->attacher())
            ->test(ListRecordings::class)
            ->callTableAction('attachExternal', $recording, ['reference' => 'ext-nowhere', 'reason' => 'Instructor sent the file'])
            ->assertNotified('Recording not attached');

        Queue::assertNotPushed(AttachExternalRecordingJob::class);
        $this->assertFalse($recording->fresh()->isManuallyAttached());
    }

    public function test_the_booking_page_offers_attach_only_for_a_lesson_with_a_meeting_and_no_recording(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('super_admin');

        $meeting = BookingMeeting::factory()->create();
        $booking = $meeting->booking;
        $booking->forceFill(['status' => BookingStatus::Completed])->save();

        Livewire::actingAs($admin)
            ->test(EditBooking::class, ['record' => $booking->getRouteKey()])
            ->assertActionVisible('attachRecording')
            ->callAction('attachRecording', ['reference' => 'ext-visible', 'reason' => 'Recorded outside the pipeline'])
            ->assertNotified('Attach queued');

        $this->assertNotNull($booking->fresh()->recording);
        Queue::assertPushed(AttachExternalRecordingJob::class);

        Livewire::actingAs($admin)
            ->test(EditBooking::class, ['record' => $booking->getRouteKey()])
            ->assertActionHidden('attachRecording');
    }

    // ── Drive link parsing lives in the adapter only ─────────────────

    public function test_drive_links_and_ids_resolve_to_the_file_id_and_junk_is_refused(): void
    {
        $id = 'abcDEF123_-xyz789';

        $this->assertSame($id, GoogleDriveRecordingStorage::fileIdFromReference($id));
        $this->assertSame($id, GoogleDriveRecordingStorage::fileIdFromReference("https://drive.google.com/file/d/{$id}/view?usp=sharing"));
        $this->assertSame($id, GoogleDriveRecordingStorage::fileIdFromReference("https://drive.google.com/open?id={$id}"));
        $this->assertSame($id, GoogleDriveRecordingStorage::fileIdFromReference("https://drive.google.com/uc?id={$id}&export=download"));
        $this->assertNull(GoogleDriveRecordingStorage::fileIdFromReference('https://example.com/file/d/'.$id.'/view'));
        $this->assertNull(GoogleDriveRecordingStorage::fileIdFromReference('short'));
        $this->assertNull(GoogleDriveRecordingStorage::fileIdFromReference(''));
    }
}
