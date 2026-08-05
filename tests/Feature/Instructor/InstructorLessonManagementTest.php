<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Enums\InstructorStatus;
use App\Lessons\Enums\LessonAttendanceStatus;
use App\Lessons\Enums\LessonOutcome;
use App\Lessons\Enums\LessonStatus;
use App\Livewire\Frontend\Instructor\LessonFeedbackManager;
use App\Models\Booking;
use App\Models\BookingMeeting;
use App\Models\Lesson;
use App\Models\User;
use App\Settings\MeetingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class InstructorLessonManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        $this->instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->instructor->assignRole('instructor');
        $this->instructor->profile()->update(['instructor_status' => InstructorStatus::Active]);

        $this->student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->student->assignRole('student');
    }

    // ── Access ────────────────────────────────────────────────────────

    public function test_instructor_can_view_own_lessons_page(): void
    {
        $this->actingAs($this->instructor)
            ->get(route('dashboard.instructor.lessons'))
            ->assertOk()
            ->assertSeeLivewire(LessonFeedbackManager::class);
    }

    public function test_student_cannot_access_instructor_lesson_page(): void
    {
        $this->actingAs($this->student)
            ->get(route('dashboard.instructor.lessons'))
            ->assertForbidden();
    }

    public function test_instructor_cannot_view_another_instructors_lesson(): void
    {
        $otherInstructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $otherInstructor->assignRole('instructor');
        $lesson = $this->makeLesson($otherInstructor, $this->student);

        // The instructor-scoped lookup (forInstructor()) makes another
        // instructor's lesson simply not exist in this instructor's
        // world — ownership is enforced by scope, not just UI hiding.
        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($this->instructor)
            ->test(LessonFeedbackManager::class)
            ->call('confirmAttendance', $lesson->id);
    }

    public function test_instructor_cannot_view_another_instructors_lesson_via_dashboard_page(): void
    {
        $otherInstructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $otherInstructor->assignRole('instructor');
        $this->makeLesson($otherInstructor, $this->student, [
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);

        $response = $this->actingAs($this->instructor)->get(route('dashboard.instructor.lessons'))->assertOk();

        $response->assertDontSee($otherInstructor->name);
    }

    public function test_instructor_only_sees_their_own_upcoming_lessons(): void
    {
        $mine = $this->makeLesson($this->instructor, $this->student, [
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);

        $otherInstructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $otherInstructor->assignRole('instructor');
        $theirs = $this->makeLesson($otherInstructor, $this->student, [
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);

        Livewire::actingAs($this->instructor)
            ->test(LessonFeedbackManager::class)
            ->assertSee($mine->student->name)
            ->assertDontSee($theirs->id);
    }

    public function test_lesson_list_displays_times_in_the_instructor_profile_timezone(): void
    {
        $this->instructor->profile()->update(['timezone' => 'Asia/Kolkata']);
        $startsAt = CarbonImmutable::parse('2026-08-05 00:30:00', 'UTC');

        $this->makeLesson($this->instructor, $this->student, [
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(30),
        ]);

        Livewire::actingAs($this->instructor)
            ->test(LessonFeedbackManager::class)
            ->assertSee('6:00 AM')
            ->assertSee('6:30 AM')
            ->assertDontSee('12:30 AM');
    }

    // ── Join ──────────────────────────────────────────────────────────

    public function test_join_class_appears_when_meeting_is_ready_and_window_is_open(): void
    {
        $lesson = $this->makeLesson($this->instructor, $this->student, [
            'starts_at' => now()->addMinutes(5),
            'ends_at' => now()->addMinutes(65),
        ]);
        BookingMeeting::factory()->created('https://meet.example.test/join-me')->create([
            'booking_id' => $lesson->booking_id,
            'starts_at' => $lesson->starts_at,
            'ends_at' => $lesson->ends_at,
        ]);

        $response = $this->actingAs($this->instructor)->get(route('dashboard.instructor.lessons'))->assertOk();

        $response->assertSee('Join Class');
        $response->assertSee('https://meet.example.test/join-me', false);
    }

    public function test_join_class_hidden_when_meeting_not_ready(): void
    {
        $lesson = $this->makeLesson($this->instructor, $this->student, [
            'starts_at' => now()->addMinutes(5),
            'ends_at' => now()->addMinutes(65),
        ]);
        // No BookingMeeting row created at all — meeting was never provisioned.

        $response = $this->actingAs($this->instructor)->get(route('dashboard.instructor.lessons'))->assertOk();

        $response->assertDontSee('Join Class');
        $response->assertSee('Meeting link unavailable');
    }

    public function test_join_class_hidden_when_too_early(): void
    {
        $lesson = $this->makeLesson($this->instructor, $this->student, [
            'starts_at' => now()->addHours(3),
            'ends_at' => now()->addHours(4),
        ]);
        BookingMeeting::factory()->created()->create([
            'booking_id' => $lesson->booking_id,
            'starts_at' => $lesson->starts_at,
            'ends_at' => $lesson->ends_at,
        ]);

        $response = $this->actingAs($this->instructor)->get(route('dashboard.instructor.lessons'))->assertOk();

        $response->assertDontSee('Join Class');
        $response->assertSee('Available shortly before lesson starts');
    }

    public function test_join_class_respects_instructor_join_url_visible_setting(): void
    {
        app(MeetingSettings::class)->instructor_join_url_visible = false;
        app(MeetingSettings::class)->save();

        $lesson = $this->makeLesson($this->instructor, $this->student, [
            'starts_at' => now()->addMinutes(5),
            'ends_at' => now()->addMinutes(65),
        ]);
        BookingMeeting::factory()->created()->create([
            'booking_id' => $lesson->booking_id,
            'starts_at' => $lesson->starts_at,
            'ends_at' => $lesson->ends_at,
        ]);

        $response = $this->actingAs($this->instructor)->get(route('dashboard.instructor.lessons'))->assertOk();

        $response->assertDontSee('Join Class');
    }

    public function test_completed_lesson_shows_no_join_action(): void
    {
        $lesson = $this->makeLesson($this->instructor, $this->student, [
            'status' => LessonStatus::Completed,
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDays(2)->addHour(),
        ]);
        BookingMeeting::factory()->created()->create([
            'booking_id' => $lesson->booking_id,
            'starts_at' => $lesson->starts_at,
            'ends_at' => $lesson->ends_at,
        ]);

        $response = $this->actingAs($this->instructor)->get(route('dashboard.instructor.lessons'))->assertOk();

        $response->assertDontSee('Join Class');
    }

    // ── Outcome / attendance / issue actions ─────────────────────────

    public function test_instructor_can_confirm_attendance_for_a_started_lesson(): void
    {
        $lesson = $this->makeLesson($this->instructor, $this->student, [
            'starts_at' => now()->subMinutes(30),
            'ends_at' => now()->addMinutes(30),
        ]);

        Livewire::actingAs($this->instructor)
            ->test(LessonFeedbackManager::class)
            ->call('confirmAttendance', $lesson->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('lesson_attendance_confirmations', [
            'lesson_id' => $lesson->id,
            'participant' => 'instructor',
        ]);
    }

    public function test_instructor_can_report_a_technical_issue(): void
    {
        $lesson = $this->makeLesson($this->instructor, $this->student, [
            'starts_at' => now()->subMinutes(30),
            'ends_at' => now()->addMinutes(30),
        ]);

        Livewire::actingAs($this->instructor)
            ->test(LessonFeedbackManager::class)
            ->call('startReportIssue', $lesson->id)
            ->set('issue_category', 'audio_issue')
            ->set('issue_description', 'Could not hear the student.')
            ->call('submitIssueReport', $lesson->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('lesson_technical_issue_reports', [
            'lesson_id' => $lesson->id,
            'reporter' => 'instructor',
            'category' => 'audio_issue',
        ]);
    }

    public function test_instructor_can_confirm_teaching_outcome_for_an_ended_lesson(): void
    {
        $lesson = $this->makeLesson($this->instructor, $this->student, [
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHour(),
            'student_attendance_status' => LessonAttendanceStatus::Attended,
            'instructor_attendance_status' => LessonAttendanceStatus::Attended,
        ]);

        Livewire::actingAs($this->instructor)
            ->test(LessonFeedbackManager::class)
            ->call('confirmOutcome', $lesson->id)
            ->assertHasNoErrors();

        $lesson->refresh();
        $this->assertSame(LessonOutcome::Completed, $lesson->outcome);
        $this->assertTrue($lesson->hasFinalizedOutcome());
    }

    public function test_confirming_outcome_before_lesson_ends_is_rejected(): void
    {
        $lesson = $this->makeLesson($this->instructor, $this->student, [
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
        ]);

        Livewire::actingAs($this->instructor)
            ->test(LessonFeedbackManager::class)
            ->call('confirmOutcome', $lesson->id);

        $lesson->refresh();
        $this->assertSame(LessonOutcome::Pending, $lesson->outcome);
        $this->assertFalse($lesson->hasFinalizedOutcome());
    }

    public function test_finalized_lesson_outcome_cannot_be_changed_via_confirm_outcome(): void
    {
        $lesson = $this->makeLesson($this->instructor, $this->student, [
            'status' => LessonStatus::StudentNoShow,
            'starts_at' => now()->subHours(3),
            'ends_at' => now()->subHours(2),
            'student_attendance_status' => LessonAttendanceStatus::NoShow,
            'instructor_attendance_status' => LessonAttendanceStatus::Attended,
            'outcome' => LessonOutcome::StudentNoShow,
            'outcome_finalized_at' => now()->subHours(2),
            'outcome_version' => 1,
        ]);

        $response = $this->actingAs($this->instructor)->get(route('dashboard.instructor.lessons'))->assertOk();
        $response->assertDontSee('Confirm Teaching Outcome');

        Livewire::actingAs($this->instructor)
            ->test(LessonFeedbackManager::class)
            ->call('confirmOutcome', $lesson->id);

        $lesson->refresh();
        $this->assertSame(LessonOutcome::StudentNoShow, $lesson->outcome);
        $this->assertSame(1, $lesson->outcome_version);
    }

    // ── Dashboard integration ─────────────────────────────────────────

    public function test_dashboard_next_lesson_widget_shows_join_class_link(): void
    {
        $lesson = $this->makeLesson($this->instructor, $this->student, [
            'starts_at' => now()->addMinutes(5),
            'ends_at' => now()->addMinutes(65),
        ]);
        BookingMeeting::factory()->created('https://meet.example.test/dashboard-join')->create([
            'booking_id' => $lesson->booking_id,
            'starts_at' => $lesson->starts_at,
            'ends_at' => $lesson->ends_at,
        ]);
        $this->instructor->profile()->update(['instructor_status' => InstructorStatus::Active]);

        $response = $this->actingAs($this->instructor->fresh())->get(route('dashboard'))->assertOk();

        $response->assertSee('Join Class');
        $response->assertSee('https://meet.example.test/dashboard-join', false);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /** @param array<string, mixed> $overrides */
    private function makeLesson(User $instructor, User $student, array $overrides = []): Lesson
    {
        $booking = Booking::factory()->confirmed()->create([
            'instructor_id' => $instructor->id,
            'student_id' => $student->id,
            'starts_at' => $overrides['starts_at'] ?? now()->addDay(),
            'ends_at' => $overrides['ends_at'] ?? now()->addDay()->addHour(),
        ]);

        return Lesson::factory()->create([
            'booking_id' => $booking->id,
            'instructor_id' => $instructor->id,
            'student_id' => $student->id,
            ...$overrides,
        ]);
    }
}
