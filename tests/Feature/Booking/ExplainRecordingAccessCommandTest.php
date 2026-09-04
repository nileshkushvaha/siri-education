<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Storage\FilesystemRecordingStorage;
use App\Enums\StudentStatus;
use App\Lessons\Enums\LessonOutcome;
use App\Models\Booking;
use App\Models\Lesson;
use App\Models\Recording;
use App\Models\User;
use App\Settings\FeatureSettings;
use App\Settings\MeetingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `recordings:explain` must name the FIRST closed gate in plain words —
 * the student UI shows nothing at all for most of them by design.
 */
final class ExplainRecordingAccessCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $features = app(FeatureSettings::class);
        $features->recording_enabled = true;
        $features->save();

        $meeting = app(MeetingSettings::class);
        $meeting->recording_enabled = true;
        $meeting->recording_student_playback_enabled = true;
        $meeting->save();
    }

    /** @return array{Booking, Recording} */
    private function deliveredBookingWithRecording(bool $finalizeLesson = true): array
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        $student->profile()->update(['student_status' => StudentStatus::Active]);
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $booking = Booking::factory()->completed()->create(['student_id' => $student->id, 'instructor_id' => $instructor->id]);

        $lesson = Lesson::factory()->completed()->withOutcome(LessonOutcome::Completed);
        if (! $finalizeLesson) {
            $lesson = Lesson::factory();
        }
        $lesson->create([
            'booking_id' => $booking->id,
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'starts_at' => $booking->starts_at,
            'ends_at' => $booking->ends_at,
        ]);

        $path = 'recordings/2026/09/lesson.mp4';
        Storage::disk('local')->put($path, 'bytes');
        $recording = Recording::factory()->available()->create([
            'booking_id' => $booking->id,
            'student_id' => $student->id,
            'teacher_id' => $instructor->id,
            'storage_driver' => FilesystemRecordingStorage::KEY,
            'storage_path' => $path,
            'mime_type' => 'video/mp4',
        ]);

        return [$booking, $recording];
    }

    public function test_every_gate_open_reports_available(): void
    {
        [$booking] = $this->deliveredBookingWithRecording();

        $this->artisan('recordings:explain', ['booking' => $booking->reference])
            ->expectsOutputToContain('Student currently sees: available')
            ->doesntExpectOutputToContain('First closed gate')
            ->assertExitCode(0);
    }

    public function test_playback_switch_off_is_named_as_the_first_closed_gate(): void
    {
        [$booking] = $this->deliveredBookingWithRecording();
        $meeting = app(MeetingSettings::class);
        $meeting->recording_student_playback_enabled = false;
        $meeting->save();

        $this->artisan('recordings:explain', ['booking' => $booking->reference])
            ->expectsOutputToContain('First closed gate: Students Can Watch Their Recordings')
            ->expectsOutputToContain('Student currently sees: hidden')
            ->assertExitCode(0);
    }

    public function test_an_unfinalized_lesson_is_named_with_the_auto_completion_hint(): void
    {
        [$booking] = $this->deliveredBookingWithRecording(finalizeLesson: false);

        $this->artisan('recordings:explain', ['booking' => $booking->reference])
            ->expectsOutputToContain('First closed gate: Lesson finalized as Completed')
            ->expectsOutputToContain('Auto-completion Delay')
            ->assertExitCode(0);
    }

    public function test_the_booking_id_works_as_well_as_the_reference(): void
    {
        [$booking] = $this->deliveredBookingWithRecording();

        $this->artisan('recordings:explain', ['booking' => (string) $booking->id])->assertExitCode(0);
    }

    public function test_an_unknown_booking_fails_clearly(): void
    {
        $this->artisan('recordings:explain', ['booking' => 'BK-NOPE'])
            ->expectsOutputToContain("No booking found for 'BK-NOPE'")
            ->assertExitCode(1);
    }
}
