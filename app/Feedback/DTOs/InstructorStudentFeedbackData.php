<?php

declare(strict_types=1);

namespace App\Feedback\DTOs;

use App\Lessons\Enums\LessonAttendanceStatus;
use App\Lessons\Enums\LessonOutcome;
use App\Models\InstructorStudentFeedback;
use Illuminate\Support\Carbon;

/**
 * Privacy-safe projection of one feedback record — an explicit field
 * allowlist, never the raw Eloquent model. No student contact detail,
 * moderation data, or financial value is ever included; the only
 * student-identifying field is the numeric id the submitting
 * instructor already legitimately knows (it is their own lesson).
 */
final readonly class InstructorStudentFeedbackData
{
    public function __construct(
        public string $id,
        public string $lessonId,
        public string $bookingId,
        public int $studentId,
        public int $instructorId,
        public LessonOutcome $outcomeSnapshot,
        public int $sourceOutcomeVersion,
        public ?LessonAttendanceStatus $attendanceStatusSnapshot,
        public ?string $attendanceObservation,
        public ?string $preparednessObservation,
        public ?string $homeworkCompletionObservation,
        public ?string $engagementObservation,
        public ?string $learningAttitudeObservation,
        public ?string $areasNeedingSupport,
        public ?string $privateNotes,
        public Carbon $submittedAt,
        public int $version,
    ) {}

    public static function fromModel(InstructorStudentFeedback $feedback): self
    {
        return new self(
            id: $feedback->id,
            lessonId: $feedback->lesson_id,
            bookingId: $feedback->booking_id,
            studentId: $feedback->student_id,
            instructorId: $feedback->instructor_id,
            outcomeSnapshot: $feedback->outcome_snapshot,
            sourceOutcomeVersion: $feedback->source_outcome_version,
            attendanceStatusSnapshot: $feedback->attendance_status_snapshot,
            attendanceObservation: $feedback->attendance_observation,
            preparednessObservation: $feedback->preparedness_observation,
            homeworkCompletionObservation: $feedback->homework_completion_observation,
            engagementObservation: $feedback->engagement_observation,
            learningAttitudeObservation: $feedback->learning_attitude_observation,
            areasNeedingSupport: $feedback->areas_needing_support,
            privateNotes: $feedback->private_notes,
            submittedAt: Carbon::instance($feedback->submitted_at),
            version: $feedback->version,
        );
    }
}
