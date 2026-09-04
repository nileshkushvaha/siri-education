<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Enums\RecordingPlaybackState;
use App\Booking\Enums\RecordingStatus;
use App\Booking\Services\RecordingPlaybackAccessResolver;
use App\Enums\StudentStatus;
use App\Lessons\Enums\LessonOutcome;
use App\Livewire\Frontend\Student\BookingHistory;
use App\Models\Booking;
use App\Models\Lesson;
use App\Models\Recording;
use App\Models\User;
use App\Settings\FeatureSettings;
use App\Settings\MeetingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * What a student is TOLD about their recording, on the booking they
 * already look at. The state is a presentation of the ingestion
 * lifecycle that never names a provider, a backend, or a failure code
 * — and it is hidden entirely whenever the student could not watch
 * anyway, so nothing advertises what cannot be opened.
 */
final class RecordingPlaybackStateTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        $this->student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->student->assignRole('student');
        $this->student->profile()->update(['student_status' => StudentStatus::Active]);
        $this->instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->instructor->assignRole('instructor');

        $this->setPlayback(true);
    }

    private function setPlayback(bool $enabled): void
    {
        $features = app(FeatureSettings::class);
        $features->recording_enabled = true;
        $features->save();

        $meeting = app(MeetingSettings::class);
        $meeting->recording_enabled = true;
        $meeting->recording_student_playback_enabled = $enabled;
        $meeting->save();
    }

    private function completedBooking(): Booking
    {
        $booking = Booking::factory()->completed()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
        ]);
        $this->deliveredLesson($booking);

        return $booking;
    }

    /** The canonical "delivered" fact: a Lesson with a finalized Completed outcome. */
    private function deliveredLesson(Booking $booking): Lesson
    {
        return Lesson::factory()->completed()->withOutcome(LessonOutcome::Completed)->create([
            'booking_id' => $booking->id,
            'student_id' => $booking->student_id,
            'instructor_id' => $booking->instructor_id,
            'starts_at' => $booking->starts_at,
            'ends_at' => $booking->ends_at,
        ]);
    }

    private function recordingFor(Booking $booking, RecordingStatus $status): Recording
    {
        $factory = $status === RecordingStatus::Available
            ? Recording::factory()->available()
            : Recording::factory();

        return $factory->create([
            'booking_id' => $booking->id,
            'student_id' => $booking->student_id,
            'teacher_id' => $booking->instructor_id,
            'status' => $status,
        ]);
    }

    private function stateFor(Booking $booking, ?User $viewer = null): RecordingPlaybackState
    {
        return app(RecordingPlaybackAccessResolver::class)->stateFor($booking->fresh()->load(['recording', 'lesson']), $viewer ?? $this->student);
    }

    public function test_a_booking_without_a_recording_shows_nothing(): void
    {
        $this->assertSame(RecordingPlaybackState::Hidden, $this->stateFor($this->completedBooking()));
    }

    public function test_a_lesson_that_has_not_ended_shows_nothing_even_with_a_pending_row(): void
    {
        $booking = Booking::factory()->confirmed()->create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);
        Lesson::factory()->create(['booking_id' => $booking->id, 'student_id' => $booking->student_id, 'instructor_id' => $booking->instructor_id]);
        $this->recordingFor($booking, RecordingStatus::Pending);

        $this->assertSame(RecordingPlaybackState::Hidden, $this->stateFor($booking));
    }

    /** Ended is not delivered: only a finalized Completed outcome opens the recording area. */
    public function test_a_lesson_that_ended_without_a_completed_outcome_shows_nothing(): void
    {
        $booking = $this->completedBooking();
        $this->recordingFor($booking, RecordingStatus::Available);

        foreach ([LessonOutcome::Pending, LessonOutcome::Cancelled, LessonOutcome::InstructorNoShow, LessonOutcome::TechnicalIssue] as $outcome) {
            $booking->lesson->forceFill(['outcome' => $outcome, 'outcome_finalized_at' => $outcome === LessonOutcome::Pending ? null : now()])->save();

            $this->assertSame(RecordingPlaybackState::Hidden, $this->stateFor($booking), $outcome->value);
        }

        $booking->lesson->delete();
        $this->assertSame(RecordingPlaybackState::Hidden, $this->stateFor($booking), 'no lesson row');
    }

    public function test_the_lifecycle_maps_to_student_facing_states(): void
    {
        $expected = [
            RecordingStatus::Pending->value => RecordingPlaybackState::Processing,
            RecordingStatus::Transferring->value => RecordingPlaybackState::Processing,
            RecordingStatus::Stored->value => RecordingPlaybackState::Processing,
            RecordingStatus::Available->value => RecordingPlaybackState::Available,
            RecordingStatus::Failed->value => RecordingPlaybackState::Unavailable,
            RecordingStatus::Expired->value => RecordingPlaybackState::Expired,
        ];

        foreach ($expected as $status => $state) {
            $booking = $this->completedBooking();
            $this->recordingFor($booking, RecordingStatus::from($status));

            $this->assertSame($state, $this->stateFor($booking), "status {$status}");
        }
    }

    public function test_a_withheld_recording_reads_as_unavailable_not_as_available(): void
    {
        $booking = $this->completedBooking();
        $recording = $this->recordingFor($booking, RecordingStatus::Available);
        $recording->forceFill(['student_access_revoked_at' => now()])->save();

        $this->assertSame(RecordingPlaybackState::Unavailable, $this->stateFor($booking));
    }

    public function test_everything_is_hidden_while_student_playback_is_off(): void
    {
        $this->setPlayback(false);

        $booking = $this->completedBooking();
        $this->recordingFor($booking, RecordingStatus::Available);

        $this->assertSame(RecordingPlaybackState::Hidden, $this->stateFor($booking));
    }

    /** A mis-scoped caller can never render another student's state. */
    public function test_the_state_is_hidden_for_anyone_but_the_bookings_student(): void
    {
        $booking = $this->completedBooking();
        $this->recordingFor($booking, RecordingStatus::Available);

        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $this->assertSame(RecordingPlaybackState::Hidden, $this->stateFor($booking, $other));
        $this->assertSame(RecordingPlaybackState::Hidden, $this->stateFor($booking, $this->instructor));
    }

    public function test_student_facing_copy_never_names_a_provider_or_backend(): void
    {
        foreach (RecordingPlaybackState::cases() as $state) {
            $copy = strtolower($state->label().' '.$state->description());

            foreach (['google', 'drive', 'zoom', 's3', 'storage', 'provider', 'failed'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $copy, $state->value);
            }
        }
    }

    // ── The booking detail the student actually sees ─────────────────

    public function test_the_booking_detail_offers_a_watch_link_only_for_an_available_recording(): void
    {
        $booking = $this->completedBooking();
        $recording = $this->recordingFor($booking, RecordingStatus::Available);

        Livewire::actingAs($this->student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->assertSee('Recording available')
            ->assertSee(route('dashboard.recordings.watch', $recording), false);
    }

    public function test_the_booking_detail_shows_processing_without_a_link(): void
    {
        $booking = $this->completedBooking();
        $recording = $this->recordingFor($booking, RecordingStatus::Pending);

        Livewire::actingAs($this->student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->assertSee('Recording processing')
            ->assertDontSee(route('dashboard.recordings.watch', $recording), false);
    }

    public function test_the_booking_detail_shows_nothing_about_recordings_when_playback_is_off(): void
    {
        $this->setPlayback(false);

        $booking = $this->completedBooking();
        $this->recordingFor($booking, RecordingStatus::Available);

        Livewire::actingAs($this->student)
            ->test(BookingHistory::class)
            ->call('viewBooking', $booking->id)
            ->assertDontSee('Recording available')
            ->assertDontSee('dashboard/recordings/');
    }
}
