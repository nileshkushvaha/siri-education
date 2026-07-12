<?php

declare(strict_types=1);

namespace App\Lessons\Actions;

use App\Booking\Enums\BookingStatus;
use App\Lessons\Contracts\LessonAttendanceRepositoryInterface;
use App\Lessons\DTOs\LessonOutcomeDetermination;
use App\Lessons\Enums\LessonAttendanceStatus;
use App\Lessons\Enums\LessonOutcome;
use App\Lessons\Enums\LessonParticipant;
use App\Lessons\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\LessonAttendanceRecord;
use App\Settings\LessonSettings;

/**
 * Pure outcome evaluation — no writes, no events. A party qualifies
 * when a human explicitly marked them Attended or the attendance
 * record carries qualifying evidence (join + minimum duration).
 * A reported technical issue always wins over attendance-derived
 * outcomes so a broken meeting can never auto-complete.
 */
final class DetermineLessonOutcomeAction
{
    public function __construct(
        private readonly LessonAttendanceRepositoryInterface $attendance,
        private readonly LessonSettings $settings,
    ) {}

    public function execute(Lesson $lesson): LessonOutcomeDetermination
    {
        $record = $this->attendance->findForLesson($lesson);
        $student = $this->qualifies($lesson, LessonParticipant::Student, $record);
        $instructor = $this->qualifies($lesson, LessonParticipant::Instructor, $record);

        if ($lesson->status === LessonStatus::Cancelled || $lesson->booking?->status === BookingStatus::Cancelled) {
            return $this->determination(LessonOutcome::Cancelled, 'booking_cancelled', $student, $instructor);
        }

        if ($record?->technical_issue_reported_at !== null) {
            return $this->determination(LessonOutcome::TechnicalIssue, 'technical_issue_reported', $student, $instructor);
        }

        if ($lesson->ends_at->isFuture()) {
            return $this->determination(LessonOutcome::Pending, 'lesson_not_ended', $student, $instructor);
        }

        return match (true) {
            $student && $instructor => $this->determination(LessonOutcome::Completed, 'attendance_qualified', $student, $instructor),
            $instructor => $this->determination(LessonOutcome::StudentNoShow, 'student_absent', $student, $instructor),
            $student => $this->determination(LessonOutcome::InstructorNoShow, 'instructor_absent', $student, $instructor),
            default => $this->determination(LessonOutcome::BothAbsent, 'both_absent', $student, $instructor),
        };
    }

    private function qualifies(Lesson $lesson, LessonParticipant $participant, ?LessonAttendanceRecord $record): bool
    {
        if ($lesson->getAttribute("{$participant->value}_attendance_status") === LessonAttendanceStatus::Attended) {
            return true;
        }

        return $record !== null
            && $record->hasQualifyingAttendance($participant, $this->settings->min_attendance_seconds);
    }

    private function determination(LessonOutcome $outcome, string $reason, bool $student, bool $instructor): LessonOutcomeDetermination
    {
        return new LessonOutcomeDetermination($outcome, $reason, $student, $instructor);
    }
}
