<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * Guards the instructor-lesson-management boundary: the instructor lesson management
 * workflow extends the existing Booking/Lesson/Meeting/Attendance/
 * Outcome domains — no duplicate Lesson/InstructorBooking/
 * InstructorSchedule/Attendance/Meeting model or service, no direct
 * UI database writes, ownership enforced server-side, and no
 * instructor cancel/reschedule action was introduced.
 */
final class Phase23KArchitectureTest extends TestCase
{
    public function test_no_duplicate_lesson_or_booking_domain_was_created(): void
    {
        $this->assertFileDoesNotExist(app_path('Models/InstructorBooking.php'));
        $this->assertFileDoesNotExist(app_path('Models/InstructorSchedule.php'));
        $this->assertFileDoesNotExist(app_path('Models/InstructorLesson.php'));
        $this->assertFileDoesNotExist(app_path('Models/TeacherLesson.php'));
        $this->assertFileDoesNotExist(app_path('Services/Instructor/InstructorLessonService.php'));
        $this->assertFileDoesNotExist(app_path('Services/Instructor/LessonManagementService.php'));
        $this->assertFileDoesNotExist(app_path('Services/Instructor/InstructorMeetingService.php'));
        $this->assertFileDoesNotExist(app_path('Services/Instructor/InstructorJoinService.php'));
    }

    public function test_lesson_list_query_is_bounded_and_eager_loaded(): void
    {
        $component = file_get_contents(app_path('Livewire/Frontend/Instructor/LessonFeedbackManager.php'));
        $this->assertIsString($component);

        $this->assertStringContainsString('->limit(20)', $component);
        $this->assertStringContainsString("->with(['student', 'subject', 'booking.meeting'])", $component);
        $this->assertStringContainsString('->paginate(10)', $component);
    }

    public function test_join_availability_reads_the_eager_loaded_relation_not_a_fresh_query(): void
    {
        $service = file_get_contents(app_path('Booking/Services/BookingMeetingService.php'));
        $this->assertIsString($service);

        // joinAvailabilityFor must read $booking->meeting (the relation),
        // never re-query via findForBooking()/BookingMeeting::query() —
        // that would reintroduce N+1 across a bounded list.
        $method = substr($service, (int) strpos($service, 'function joinAvailabilityFor'));
        $method = substr($method, 0, (int) strpos($method, "\n    }"));
        $this->assertStringContainsString('$booking->meeting', $method);
        $this->assertStringNotContainsString('findForBooking', $method);
        $this->assertStringNotContainsString('BookingMeeting::query', $method);
    }

    public function test_livewire_component_never_writes_to_lesson_or_booking_directly(): void
    {
        $component = file_get_contents(app_path('Livewire/Frontend/Instructor/LessonFeedbackManager.php'));
        $this->assertIsString($component);

        $this->assertStringNotContainsString('Lesson::create(', $component);
        $this->assertStringNotContainsString('->fill(', $component);
        $this->assertStringNotContainsString('$lesson->save()', $component);
        $this->assertStringNotContainsString('$lesson->status =', $component);
        $this->assertStringNotContainsString('$lesson->outcome =', $component);

        // Every mutation is delegated to an existing domain service.
        $this->assertStringContainsString('LessonConfirmationServiceInterface', $component);
        $this->assertStringContainsString('LessonOutcomeServiceInterface', $component);
        $this->assertStringContainsString('confirmations->submitConfirmation(', $component);
        $this->assertStringContainsString('confirmations->reportTechnicalIssue(', $component);
        $this->assertStringContainsString('outcomes->finalize(', $component);
    }

    public function test_ownership_is_enforced_by_scoped_lookup_and_existing_policy_abilities(): void
    {
        $component = file_get_contents(app_path('Livewire/Frontend/Instructor/LessonFeedbackManager.php'));
        $this->assertIsString($component);

        // Every mutating action re-scopes to the acting instructor before
        // authorizing — ownership never relies on a client-supplied id alone.
        $this->assertStringContainsString('forInstructor((int) auth()->id())', $component);
        $this->assertStringContainsString("authorize('submitAttendance'", $component);
        $this->assertStringContainsString("authorize('reportTechnicalIssue'", $component);
        $this->assertStringContainsString("authorize('complete'", $component);

        // No new Lesson policy abilities were introduced — this
        // reuses view/complete/submitAttendance/reportTechnicalIssue,
        // all of which already existed.
        $policy = file_get_contents(app_path('Policies/LessonPolicy.php'));
        $this->assertIsString($policy);
        $abilityCount = preg_match_all('/public function \w+\(/', $policy);
        $this->assertSame(17, $abilityCount, 'LessonPolicy should still expose exactly its pre-existing 17 abilities — no new ability method was added here.');
    }

    public function test_no_instructor_cancel_or_reschedule_action_was_introduced(): void
    {
        $component = file_get_contents(app_path('Livewire/Frontend/Instructor/LessonFeedbackManager.php'));
        $this->assertIsString($component);

        $this->assertStringNotContainsString('function cancelBooking', $component);
        $this->assertStringNotContainsString('function cancelLesson', $component);
        $this->assertStringNotContainsString('function reschedule', $component);
        $this->assertStringNotContainsString('CancelBookingAction', $component);
        $this->assertStringNotContainsString('RescheduleBookingAction', $component);

        $view = file_get_contents(resource_path('views/livewire/frontend/instructor/lesson-detail-panel.blade.php'));
        $this->assertIsString($view);
        $this->assertStringNotContainsString('Cancel Lesson', $view);
        $this->assertStringNotContainsString('Reschedule', $view);
    }

    public function test_dashboard_widget_reuses_the_existing_bounded_next_lessons_query(): void
    {
        $service = file_get_contents(app_path('Services/Instructor/InstructorDashboardService.php'));
        $this->assertIsString($service);

        // Still the single bounded LIMIT 4 query — no
        // second/duplicate lesson query path was added for the join button.
        $this->assertStringContainsString('->limit(4)', $service);
        $this->assertSame(1, substr_count($service, '->limit(4)'));
        $this->assertStringContainsString('joinAvailabilityFor', $service);
    }
}
