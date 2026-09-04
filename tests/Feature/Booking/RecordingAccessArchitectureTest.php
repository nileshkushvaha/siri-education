<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Enums\RecordingStatus;
use App\Booking\Storage\FilesystemRecordingStorage;
use App\Enums\StudentStatus;
use App\Lessons\Enums\LessonOutcome;
use App\Models\Booking;
use App\Models\Lesson;
use App\Models\Recording;
use App\Models\User;
use App\Policies\RecordingPolicy;
use App\Settings\FeatureSettings;
use App\Settings\MeetingSettings;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Durable safeguards for the recording ACCESS rule (SRS §12.20):
 *
 *   the lesson's own STUDENT may watch — only while student playback is
 *   enabled, the recording is serveable, and it has not been withheld;
 *   the INSTRUCTOR has no access; ADMINISTRATORS need the explicit
 *   permission; nobody downloads but a permitted administrator; no
 *   recording is ever reachable by a link alone.
 *
 * The per-request checks live in RecordingStudentPlaybackTest and
 * RecordingDownloadTest. What lives here is structural: the things
 * that would let access widen months from now — an instructor branch,
 * a second byte-serving route, a student download, a locator in a
 * payload, a participant notification nobody decided on.
 */
final class RecordingAccessArchitectureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
    }

    private function enableStudentPlayback(): void
    {
        $features = app(FeatureSettings::class);
        $features->recording_enabled = true;
        $features->save();

        $meeting = app(MeetingSettings::class);
        $meeting->recording_enabled = true;
        $meeting->recording_student_playback_enabled = true;
        $meeting->save();
    }

    private function storedRecording(User $student, User $instructor): Recording
    {
        $path = 'recordings/2026/08/lesson-'.uniqid().'.mp4';
        Storage::disk('local')->put($path, 'bytes');

        $booking = Booking::factory()->completed()->create([
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
        ]);
        Lesson::factory()->completed()->withOutcome(LessonOutcome::Completed)->create([
            'booking_id' => $booking->id,
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
        ]);

        return Recording::factory()->available()->create([
            'booking_id' => $booking->id,
            'student_id' => $student->id,
            'teacher_id' => $instructor->id,
            'storage_driver' => FilesystemRecordingStorage::KEY,
            'storage_path' => $path,
        ]);
    }

    // ── Policy shape ──────────────────────────────────────────────────

    /**
     * The instructor has no grant. A `teacher_id` comparison inside
     * RecordingPolicy is precisely how one would return unnoticed.
     */
    public function test_the_recording_policy_never_branches_on_instructor_identity(): void
    {
        $source = php_strip_whitespace(app_path('Policies/RecordingPolicy.php'));

        foreach (['teacher_id', 'instructor_id', '$recording->teacher', '$recording->instructor', 'isInstructor'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                'RecordingPolicy must not grant access based on having delivered the lesson',
            );
        }
    }

    /** The student rule has exactly one home; the policy must defer to it rather than restate it. */
    public function test_the_student_rule_is_written_once_in_the_playback_access_resolver(): void
    {
        $policy = php_strip_whitespace(app_path('Policies/RecordingPolicy.php'));

        $this->assertStringContainsString('RecordingPlaybackAccessResolver', $policy);
        $this->assertStringNotContainsString('student_id', $policy, 'the ownership comparison belongs to the resolver, not the policy');
    }

    public function test_the_gate_grants_only_the_own_student_and_a_permitted_admin(): void
    {
        $this->enableStudentPlayback();

        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        $student->profile()->update(['student_status' => StudentStatus::Active]);
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $other->assignRole('student');
        $other->profile()->update(['student_status' => StudentStatus::Active]);

        $recording = $this->storedRecording($student, $instructor);

        $this->assertTrue(Gate::forUser($student)->allows('watch', $recording));
        $this->assertFalse(Gate::forUser($student)->allows('view', $recording), 'watching is not admin visibility');
        $this->assertFalse(Gate::forUser($student)->allows('download', $recording), 'a student never downloads');

        $this->assertFalse(Gate::forUser($instructor)->allows('watch', $recording));
        $this->assertFalse(Gate::forUser($instructor)->allows('view', $recording));
        $this->assertFalse(Gate::forUser($instructor)->allows('download', $recording));

        $this->assertFalse(Gate::forUser($other)->allows('watch', $recording));

        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->givePermissionTo(Permission::firstOrCreate(['name' => 'View:Recording', 'guard_name' => 'web']));

        $this->assertTrue(Gate::forUser($admin)->allows('view', $recording));
        $this->assertTrue(Gate::forUser($admin)->allows('watch', $recording));
        $this->assertTrue(Gate::forUser($admin)->allows('download', $recording));
    }

    public function test_the_policy_exposes_no_ability_beyond_the_documented_set(): void
    {
        $abilities = array_values(array_diff(
            get_class_methods(RecordingPolicy::class),
            get_class_methods(HandlesAuthorization::class),
            ['__construct'],
        ));

        sort($abilities);

        $this->assertSame(['download', 'retry', 'view', 'viewAny', 'watch', 'withhold'], $abilities);
    }

    // ── No participant notification (undecided, so absent) ────────────

    /**
     * SRS §17 lists "Recording available, if enabled" as a possible
     * participant notification, but no decision has enabled it. Until
     * one does, no such notification class may exist — reintroducing
     * it must be a deliberate product change, not a side effect.
     */
    public function test_no_recording_available_notification_class_exists(): void
    {
        $notifications = File::isDirectory(app_path('Notifications'))
            ? File::allFiles(app_path('Notifications'))
            : [];

        foreach ($notifications as $file) {
            $this->assertStringNotContainsStringIgnoringCase(
                'recordingavailable',
                $file->getFilename(),
                'a recording-available participant notification has appeared without a product decision',
            );
        }
    }

    public function test_the_recording_lifecycle_notifier_sends_nothing_to_participants(): void
    {
        $source = php_strip_whitespace(app_path('Booking/Services/RecordingLifecycleNotifier.php'));

        foreach (['->notify(', 'student->', 'teacher->', 'NotificationIdempotencyGuard'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }

    // ── Exactly the documented routes ─────────────────────────────────

    /**
     * Three routes touch recording bytes or pages, each authorizing on
     * every request. A fourth — an API resource, a signed URL helper, a
     * student download — is how this rule gets bypassed.
     */
    public function test_only_the_documented_routes_serve_recordings(): void
    {
        $recordingRoutes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_contains((string) $route->uri(), 'recording'))
            ->map(fn ($route): string => $route->methods()[0].' '.$route->uri())
            ->values()
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            'GET admin/recordings',
            'GET admin/recordings/{recording}/download',
            'GET admin/recordings/{record}',
            'GET dashboard/recordings/{recording}',
            'GET dashboard/recordings/{recording}/stream',
            'POST api/webhooks/meetings/recordings/{provider}',
        ], $recordingRoutes);
    }

    /** No student- or instructor-facing view may link to the DOWNLOAD route. */
    public function test_no_participant_view_references_the_download_route(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $source = (string) file_get_contents($file->getPathname());

            if (str_contains($source, 'recordings.download')) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, 'no participant-facing view may expose the recording download');
    }

    /** No participant-facing view may reach a recording through anything but the watch route. */
    public function test_participant_views_reference_recordings_only_through_the_watch_route(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $source = (string) file_get_contents($file->getPathname());

            foreach (['storage_path', 'storage_driver', 'provider_reference', 'drive.google.com', 'googleapis.com'] as $forbidden) {
                if (str_contains($source, $forbidden)) {
                    $offenders[] = $file->getRelativePathname().' ['.$forbidden.']';
                }
            }
        }

        $this->assertSame([], $offenders, 'no view may carry a storage or provider identifier');
    }

    // ── Nothing leaks through serialization ───────────────────────────

    public function test_a_serialized_recording_carries_no_storage_or_provider_locator(): void
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $recording = $this->storedRecording($student, User::factory()->create());

        $serialized = $recording->toArray();

        foreach (['storage_path', 'storage_driver', 'storage_checksum'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $serialized);
        }
    }

    /** Expired recordings keep audit metadata but must never be served again — to anyone. */
    public function test_an_expired_recording_is_neither_watchable_nor_downloadable_by_anyone(): void
    {
        $this->enableStudentPlayback();

        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $recording = $this->storedRecording($student, User::factory()->create());
        $recording->forceFill(['status' => RecordingStatus::Expired, 'storage_path' => null])->save();

        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->givePermissionTo(Permission::firstOrCreate(['name' => 'View:Recording', 'guard_name' => 'web']));

        $this->assertTrue(Gate::forUser($admin)->allows('view', $recording));
        $this->assertFalse(Gate::forUser($admin)->allows('download', $recording->fresh()));
        $this->assertFalse(Gate::forUser($admin)->allows('watch', $recording->fresh()));
        $this->assertFalse(Gate::forUser($student)->allows('watch', $recording->fresh()));
    }
}
