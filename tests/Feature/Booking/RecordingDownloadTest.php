<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Enums\RecordingStatus;
use App\Booking\Storage\FilesystemRecordingStorage;
use App\Models\Recording;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Delivery is where two rules either hold or do not:
 *
 *  1. DOWNLOAD IS ADMIN-ONLY. The lesson's own student may WATCH a
 *     recording inside SIRI (RecordingStudentPlaybackTest), but being
 *     able to watch is not being able to take the file away. The
 *     student and instructor tests below assert DENIAL of download,
 *     and they are the most important tests in this file.
 *  2. SIRI is the authorization layer, not the storage backend. Every
 *     request re-checks the policy, the URL carries the recording
 *     rather than a storage identifier, and the body is proxied — so
 *     nothing about where the bytes live reaches the browser.
 */
final class RecordingDownloadTest extends TestCase
{
    use RefreshDatabase;

    private const string CONTENT = 'the lesson recording bytes';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
    }

    private function activeStudent(): User
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');

        return $student;
    }

    private function activeInstructor(): User
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');

        return $instructor;
    }

    private function permittedAdmin(): User
    {
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->givePermissionTo(Permission::firstOrCreate(['name' => 'View:Recording', 'guard_name' => 'web']));

        return $admin;
    }

    /** An Available recording whose object really exists on the faked disk. */
    private function storedRecording(User $student, User $instructor): Recording
    {
        $path = 'recordings/2026/08/lesson-'.uniqid().'.mp4';
        Storage::disk('local')->put($path, self::CONTENT);

        return Recording::factory()->available()->create([
            'student_id' => $student->id,
            'teacher_id' => $instructor->id,
            'storage_driver' => FilesystemRecordingStorage::KEY,
            'storage_path' => $path,
            'size_bytes' => strlen(self::CONTENT),
            'mime_type' => 'video/mp4',
        ]);
    }

    // ── Who may download: administrators, and nobody else ─────────────

    /**
     * THE core assertion. The student whose own lesson this is must be
     * refused a DOWNLOAD — playback is their route, and it is
     * deliberately separate from taking the original away.
     */
    public function test_the_recordings_own_student_is_denied(): void
    {
        $student = $this->activeStudent();
        $recording = $this->storedRecording($student, $this->activeInstructor());

        $this->actingAs($student)
            ->get(route('admin.recordings.download', $recording))
            ->assertForbidden();
    }

    /** Same rule for the instructor who actually delivered the lesson. */
    public function test_the_recordings_own_instructor_is_denied(): void
    {
        $instructor = $this->activeInstructor();
        $recording = $this->storedRecording($this->activeStudent(), $instructor);

        $this->actingAs($instructor)
            ->get(route('admin.recordings.download', $recording))
            ->assertForbidden();
    }

    public function test_an_unrelated_user_is_denied(): void
    {
        $recording = $this->storedRecording($this->activeStudent(), $this->activeInstructor());

        $this->actingAs($this->activeStudent())
            ->get(route('admin.recordings.download', $recording))
            ->assertForbidden();
    }

    /** An admin WITHOUT the recording permission is denied too — role is not enough. */
    public function test_an_admin_without_the_recording_permission_is_denied(): void
    {
        $recording = $this->storedRecording($this->activeStudent(), $this->activeInstructor());
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $this->actingAs($admin)
            ->get(route('admin.recordings.download', $recording))
            ->assertForbidden();
    }

    public function test_the_streamed_response_returns_the_stored_content_for_a_permitted_admin(): void
    {
        $recording = $this->storedRecording($this->activeStudent(), $this->activeInstructor());
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->givePermissionTo(Permission::firstOrCreate(['name' => 'View:Recording', 'guard_name' => 'web']));

        $response = $this->actingAs($admin)->get(route('admin.recordings.download', $recording));

        $this->assertSame(self::CONTENT, $response->streamedContent());
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $recording = $this->storedRecording($this->activeStudent(), $this->activeInstructor());

        // The admin group's guest redirect targets the admin login.
        $response = $this->get(route('admin.recordings.download', $recording));

        $response->assertRedirect();
        $this->assertStringEndsWith('/login', (string) $response->headers->get('Location'));
    }

    /**
     * Guessing or swapping the route id gets a participant nowhere,
     * because participation was never the download grant.
     */
    public function test_a_student_cannot_reach_another_students_recording_by_changing_the_id(): void
    {
        $recordingA = $this->storedRecording($this->activeStudent(), $this->activeInstructor());

        $studentB = $this->activeStudent();
        $this->storedRecording($studentB, $this->activeInstructor());

        $this->actingAs($studentB)
            ->get(route('admin.recordings.download', $recordingA))
            ->assertForbidden();
    }

    public function test_an_admin_holding_the_view_permission_may_download(): void
    {
        $recording = $this->storedRecording($this->activeStudent(), $this->activeInstructor());

        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->givePermissionTo(Permission::firstOrCreate(['name' => 'View:Recording', 'guard_name' => 'web']));

        $this->actingAs($admin)
            ->get(route('admin.recordings.download', $recording))
            ->assertOk();
    }

    /**
     * The people who actually hold View:Recording use the admin portal
     * and are redirected away from /dashboard/*. The download therefore
     * lives under /admin — and a real manager must reach it, not only a
     * role-less test user.
     */
    public function test_a_manager_with_the_permission_reaches_the_download_through_the_admin_portal(): void
    {
        $recording = $this->storedRecording($this->activeStudent(), $this->activeInstructor());

        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole(Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']));
        $manager->givePermissionTo(Permission::firstOrCreate(['name' => 'View:Recording', 'guard_name' => 'web']));

        $response = $this->actingAs($manager)->get(route('admin.recordings.download', $recording));

        $response->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="lesson-'.$recording->booking->reference.'.mp4"');
        $this->assertSame(self::CONTENT, $response->streamedContent());
    }

    /** The admin route is a real route for a student too — and refuses them by policy, not by obscurity. */
    public function test_a_student_reaching_the_admin_download_route_is_refused(): void
    {
        $student = $this->activeStudent();
        $recording = $this->storedRecording($student, $this->activeInstructor());

        $this->actingAs($student)
            ->get(route('admin.recordings.download', $recording))
            ->assertForbidden();
    }

    // ── What is downloadable ──────────────────────────────────────────

    /** Metadata survives retention expiry, but there is no longer anything to serve — even for an admin. */
    public function test_an_expired_recording_cannot_be_downloaded(): void
    {
        $recording = $this->storedRecording($this->activeStudent(), $this->activeInstructor());
        $recording->forceFill(['status' => RecordingStatus::Expired, 'storage_path' => null])->save();

        $this->actingAs($this->permittedAdmin())
            ->get(route('admin.recordings.download', $recording))
            ->assertForbidden();
    }

    public function test_a_recording_still_being_transferred_cannot_be_downloaded(): void
    {
        $recording = $this->storedRecording($this->activeStudent(), $this->activeInstructor());
        $recording->forceFill(['status' => RecordingStatus::Stored])->save();

        $this->actingAs($this->permittedAdmin())
            ->get(route('admin.recordings.download', $recording))
            ->assertForbidden();
    }

    // ── What is never exposed ─────────────────────────────────────────

    /**
     * The URL and the response must reveal nothing about the storage
     * backend — otherwise the S3 migration would be a user-visible
     * change, and a Drive file id would be loose in the wild.
     */
    public function test_the_response_never_exposes_the_storage_locator_or_a_backend_url(): void
    {
        $recording = $this->storedRecording($this->activeStudent(), $this->activeInstructor());

        $response = $this->actingAs($this->permittedAdmin())->get(route('admin.recordings.download', $recording));

        $headers = json_encode($response->headers->all());
        $this->assertStringNotContainsString($recording->storage_path, $headers);
        $this->assertStringNotContainsString('drive.google.com', $headers);
        $this->assertStringNotContainsString('googleapis.com', $headers);

        // The URL itself keys on the recording, never on a storage id.
        $this->assertStringNotContainsString(
            $recording->storage_path,
            route('admin.recordings.download', $recording),
        );
    }

    public function test_private_recordings_are_never_cacheable_by_a_shared_proxy(): void
    {
        $recording = $this->storedRecording($this->activeStudent(), $this->activeInstructor());

        $this->actingAs($this->permittedAdmin())
            ->get(route('admin.recordings.download', $recording))
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * Serializing a Recording must not carry the locator either — an
     * API response or Livewire payload is just as public as a header.
     */
    public function test_serializing_a_recording_hides_its_storage_locator(): void
    {
        $recording = $this->storedRecording($this->activeStudent(), $this->activeInstructor());

        $serialized = $recording->toArray();

        $this->assertArrayNotHasKey('storage_path', $serialized);
        $this->assertArrayNotHasKey('storage_driver', $serialized);
        $this->assertArrayNotHasKey('storage_checksum', $serialized);
    }
}
