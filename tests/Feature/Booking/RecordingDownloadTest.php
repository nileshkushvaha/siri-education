<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Models\Recording;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * GAP-028 requirement #6 — private storage with authorization
 * re-checked on every request. Mirrors HomeworkResourceDownloadController's
 * own test conventions exactly (route parameter is the Media model).
 */
final class RecordingDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
    }

    /** Minimal bytes finfo genuinely detects as video/mp4 — Recording's media collection validates real detected mime. */
    private function fakeMp4Bytes(): string
    {
        return "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41".str_repeat("\x00", 200);
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

    private function recordingWithMedia(User $student, User $instructor): Recording
    {
        $recording = Recording::factory()->available()->create([
            'student_id' => $student->id,
            'teacher_id' => $instructor->id,
        ]);

        $recording->addMediaFromString($this->fakeMp4Bytes())
            ->usingFileName('lesson.mp4')
            ->toMediaCollection('file');

        return $recording->fresh();
    }

    public function test_the_recordings_own_student_and_instructor_can_download_it(): void
    {
        $student = $this->activeStudent();
        $instructor = $this->activeInstructor();
        $recording = $this->recordingWithMedia($student, $instructor);
        $media = $recording->getFirstMedia('file');

        $this->actingAs($student)
            ->get(route('dashboard.recordings.download', $media))
            ->assertOk();

        $this->actingAs($instructor)
            ->get(route('dashboard.recordings.download', $media))
            ->assertOk();
    }

    public function test_an_unrelated_user_is_denied_without_the_explicit_view_permission(): void
    {
        $student = $this->activeStudent();
        $instructor = $this->activeInstructor();
        $recording = $this->recordingWithMedia($student, $instructor);
        $media = $recording->getFirstMedia('file');

        $stranger = $this->activeStudent();

        $this->actingAs($stranger)
            ->get(route('dashboard.recordings.download', $media))
            ->assertForbidden();
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $student = $this->activeStudent();
        $instructor = $this->activeInstructor();
        $recording = $this->recordingWithMedia($student, $instructor);
        $media = $recording->getFirstMedia('file');

        $this->get(route('dashboard.recordings.download', $media))
            ->assertRedirect(route('auth.login'));
    }

    public function test_a_stranger_cannot_use_another_recordings_media_id_to_view_a_different_recording(): void
    {
        $studentA = $this->activeStudent();
        $instructorA = $this->activeInstructor();
        $recordingA = $this->recordingWithMedia($studentA, $instructorA);
        $mediaA = $recordingA->getFirstMedia('file');

        $studentB = $this->activeStudent();
        $instructorB = $this->activeInstructor();
        $this->recordingWithMedia($studentB, $instructorB);

        $this->actingAs($studentB)
            ->get(route('dashboard.recordings.download', $mediaA))
            ->assertForbidden();
    }

    public function test_an_expired_recording_with_no_media_left_returns_not_found(): void
    {
        $student = $this->activeStudent();
        $instructor = $this->activeInstructor();
        $recording = $this->recordingWithMedia($student, $instructor);
        $media = $recording->getFirstMedia('file');
        $mediaId = $media->id;

        $recording->clearMediaCollection('file');

        $this->actingAs($student)
            ->get(route('dashboard.recordings.download', $mediaId))
            ->assertNotFound();
    }
}
