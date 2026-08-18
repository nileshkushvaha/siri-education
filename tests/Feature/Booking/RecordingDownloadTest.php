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
 * Delivery is where the "Drive is storage, SIRI is authorization"
 * rule either holds or does not.
 *
 * Every request re-checks the policy against the currently
 * authenticated user, the URL carries the recording — not a storage
 * identifier — and the response body is proxied from the backend, so
 * nothing about where the bytes live is ever exposed to the browser.
 * These tests assert exactly that, plus the ID-swapping attempt that
 * a broken authorization boundary would allow.
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

    // ── Who may download ──────────────────────────────────────────────

    public function test_the_recordings_own_student_and_instructor_can_download_it(): void
    {
        $student = $this->activeStudent();
        $instructor = $this->activeInstructor();
        $recording = $this->storedRecording($student, $instructor);

        $this->actingAs($student)
            ->get(route('dashboard.recordings.download', $recording))
            ->assertOk();

        $this->actingAs($instructor)
            ->get(route('dashboard.recordings.download', $recording))
            ->assertOk();
    }

    public function test_the_streamed_response_returns_the_stored_content(): void
    {
        $student = $this->activeStudent();
        $recording = $this->storedRecording($student, $this->activeInstructor());

        $response = $this->actingAs($student)->get(route('dashboard.recordings.download', $recording));

        $this->assertSame(self::CONTENT, $response->streamedContent());
    }

    public function test_an_unrelated_user_is_denied_without_the_explicit_view_permission(): void
    {
        $recording = $this->storedRecording($this->activeStudent(), $this->activeInstructor());

        $this->actingAs($this->activeStudent())
            ->get(route('dashboard.recordings.download', $recording))
            ->assertForbidden();
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $recording = $this->storedRecording($this->activeStudent(), $this->activeInstructor());

        $this->get(route('dashboard.recordings.download', $recording))
            ->assertRedirect(route('auth.login'));
    }

    /**
     * The classic IDOR attempt: a legitimate student swapping in
     * another lesson's recording id.
     */
    public function test_a_student_cannot_reach_another_students_recording_by_changing_the_id(): void
    {
        $recordingA = $this->storedRecording($this->activeStudent(), $this->activeInstructor());

        $studentB = $this->activeStudent();
        $this->storedRecording($studentB, $this->activeInstructor());

        $this->actingAs($studentB)
            ->get(route('dashboard.recordings.download', $recordingA))
            ->assertForbidden();
    }

    /** An instructor's access is scoped to lessons they actually delivered. */
    public function test_an_instructor_cannot_reach_a_recording_from_a_lesson_they_did_not_teach(): void
    {
        $recording = $this->storedRecording($this->activeStudent(), $this->activeInstructor());

        $this->actingAs($this->activeInstructor())
            ->get(route('dashboard.recordings.download', $recording))
            ->assertForbidden();
    }

    public function test_an_admin_holding_the_view_permission_may_download(): void
    {
        $recording = $this->storedRecording($this->activeStudent(), $this->activeInstructor());

        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->givePermissionTo(Permission::firstOrCreate(['name' => 'View:Recording', 'guard_name' => 'web']));

        $this->actingAs($admin)
            ->get(route('dashboard.recordings.download', $recording))
            ->assertOk();
    }

    // ── What is downloadable ──────────────────────────────────────────

    /** Metadata survives retention expiry, but there is no longer anything to serve. */
    public function test_an_expired_recording_cannot_be_downloaded(): void
    {
        $student = $this->activeStudent();
        $recording = $this->storedRecording($student, $this->activeInstructor());
        $recording->forceFill(['status' => RecordingStatus::Expired, 'storage_path' => null])->save();

        $this->actingAs($student)
            ->get(route('dashboard.recordings.download', $recording))
            ->assertForbidden();
    }

    public function test_a_recording_still_being_transferred_cannot_be_downloaded(): void
    {
        $student = $this->activeStudent();
        $recording = $this->storedRecording($student, $this->activeInstructor());
        $recording->forceFill(['status' => RecordingStatus::Stored])->save();

        $this->actingAs($student)
            ->get(route('dashboard.recordings.download', $recording))
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
        $student = $this->activeStudent();
        $recording = $this->storedRecording($student, $this->activeInstructor());

        $response = $this->actingAs($student)->get(route('dashboard.recordings.download', $recording));

        $headers = json_encode($response->headers->all());
        $this->assertStringNotContainsString($recording->storage_path, $headers);
        $this->assertStringNotContainsString('drive.google.com', $headers);
        $this->assertStringNotContainsString('googleapis.com', $headers);

        // The URL itself keys on the recording, never on a storage id.
        $this->assertStringNotContainsString(
            $recording->storage_path,
            route('dashboard.recordings.download', $recording),
        );
    }

    public function test_private_recordings_are_never_cacheable_by_a_shared_proxy(): void
    {
        $student = $this->activeStudent();
        $recording = $this->storedRecording($student, $this->activeInstructor());

        $this->actingAs($student)
            ->get(route('dashboard.recordings.download', $recording))
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
