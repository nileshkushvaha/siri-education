<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Enums\RecordingStatus;
use App\Booking\Storage\FilesystemRecordingStorage;
use App\Models\Recording;
use App\Models\User;
use App\Policies\RecordingPolicy;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Durable safeguards for the ADMIN-ONLY recording rule.
 *
 * The per-request authorization checks live in RecordingDownloadTest.
 * What lives here is structural: the things that would let participant
 * access creep back in months from now, when the reason for the rule
 * has been forgotten. Each test below fails loudly if someone
 * reintroduces a participant path — a policy branch, a notification, a
 * route, or a serialized locator.
 */
final class RecordingPrivacyArchitectureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
    }

    private function storedRecording(User $student, User $instructor): Recording
    {
        $path = 'recordings/2026/08/lesson-'.uniqid().'.mp4';
        Storage::disk('local')->put($path, 'bytes');

        return Recording::factory()->available()->create([
            'student_id' => $student->id,
            'teacher_id' => $instructor->id,
            'storage_driver' => FilesystemRecordingStorage::KEY,
            'storage_path' => $path,
        ]);
    }

    // ── Policy shape ──────────────────────────────────────────────────

    /**
     * The policy must not consult participant identity at all. A
     * `student_id` or `teacher_id` comparison inside RecordingPolicy is
     * precisely how participant access would return.
     */
    public function test_the_recording_policy_never_branches_on_participant_identity(): void
    {
        $source = php_strip_whitespace(app_path('Policies/RecordingPolicy.php'));

        foreach (['student_id', 'teacher_id', 'isParticipant', '$recording->student', '$recording->teacher'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                'RecordingPolicy must not grant access based on lesson participation',
            );
        }
    }

    /** Gate-level proof, independent of any HTTP route. */
    public function test_the_gate_denies_participants_and_allows_only_a_permitted_admin(): void
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');

        $recording = $this->storedRecording($student, $instructor);

        $this->assertFalse(Gate::forUser($student)->allows('view', $recording));
        $this->assertFalse(Gate::forUser($student)->allows('download', $recording));
        $this->assertFalse(Gate::forUser($instructor)->allows('view', $recording));
        $this->assertFalse(Gate::forUser($instructor)->allows('download', $recording));

        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->givePermissionTo(Permission::firstOrCreate(['name' => 'View:Recording', 'guard_name' => 'web']));

        $this->assertTrue(Gate::forUser($admin)->allows('view', $recording));
        $this->assertTrue(Gate::forUser($admin)->allows('download', $recording));
    }

    public function test_the_policy_exposes_no_ability_beyond_view_download_and_retry(): void
    {
        $abilities = array_values(array_diff(
            get_class_methods(RecordingPolicy::class),
            get_class_methods(HandlesAuthorization::class),
        ));

        sort($abilities);

        $this->assertSame(['download', 'retry', 'view', 'viewAny'], $abilities);
    }

    // ── No participant notification ───────────────────────────────────

    /**
     * Telling a student their recording is ready would advertise
     * something they cannot open. The notification class was deleted
     * outright rather than left unused, and this asserts it stays gone.
     */
    public function test_no_recording_available_notification_class_exists(): void
    {
        // File existence rather than class_exists(): the assertion must
        // hold regardless of autoloader state, and a stale classmap
        // entry should not be able to mask a real reintroduction.
        $this->assertFileDoesNotExist(
            app_path('Notifications/Booking/RecordingAvailableNotification.php'),
            'participant recording-available notifications must not be reintroduced',
        );

        $notifications = File::isDirectory(app_path('Notifications'))
            ? File::allFiles(app_path('Notifications'))
            : [];

        foreach ($notifications as $file) {
            $this->assertStringNotContainsStringIgnoringCase(
                'recordingavailable',
                $file->getFilename(),
                'a recording-available participant notification has reappeared',
            );
        }
    }

    /** The lifecycle notifier must not be able to notify a participant. */
    public function test_the_recording_lifecycle_notifier_sends_nothing_to_participants(): void
    {
        $source = php_strip_whitespace(app_path('Booking/Services/RecordingLifecycleNotifier.php'));

        foreach (['->notify(', 'student->', 'teacher->', 'NotificationIdempotencyGuard'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                'RecordingLifecycleNotifier must not deliver recording notifications to participants',
            );
        }
    }

    // ── No participant route ──────────────────────────────────────────

    /**
     * Exactly one route may serve recording bytes, and it authorizes on
     * every request. A second route — a student dashboard link, an API
     * resource, a signed URL helper — is how this rule gets bypassed.
     */
    public function test_only_one_route_serves_recordings(): void
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
            'GET admin/recordings/{record}',
            'GET dashboard/recordings/{recording}/download',
            'POST api/webhooks/meetings/recordings/{provider}',
        ], $recordingRoutes);
    }

    /** No student- or instructor-facing view may link to a recording. */
    public function test_no_participant_view_references_a_recording_route(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            $source = (string) file_get_contents($file->getPathname());

            if (str_contains($source, 'recordings.download') || str_contains($source, "route('dashboard.recordings")) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, 'no participant-facing view may expose a recording link');
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

    /** Expired recordings keep audit metadata but must never be downloadable again. */
    public function test_an_expired_recording_is_not_downloadable_by_anyone(): void
    {
        $recording = $this->storedRecording(User::factory()->create(), User::factory()->create());
        $recording->forceFill(['status' => RecordingStatus::Expired, 'storage_path' => null])->save();

        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->givePermissionTo(Permission::firstOrCreate(['name' => 'View:Recording', 'guard_name' => 'web']));

        $this->assertTrue(Gate::forUser($admin)->allows('view', $recording));
        $this->assertFalse(Gate::forUser($admin)->allows('download', $recording->fresh()));
    }
}
