<?php

declare(strict_types=1);

namespace App\Feedback\Actions;

use App\Booking\Enums\BookingStatus;
use App\Feedback\Contracts\InstructorStudentFeedbackRepositoryInterface;
use App\Feedback\DTOs\SubmitInstructorStudentFeedbackData;
use App\Feedback\Events\InstructorStudentFeedbackSubmitted;
use App\Feedback\Exceptions\InstructorStudentFeedbackException;
use App\Lessons\Enums\LessonOutcome;
use App\Models\InstructorStudentFeedback;
use App\Models\Lesson;
use App\Models\User;
use App\Reviews\DTOs\SanitizedReviewContent;
use App\Reviews\Support\ReviewContentSanitizer;
use App\Services\AuditTrailService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Submits one instructor-to-student lesson feedback record. Self-
 * contained transaction: locks the lesson row (serializing a
 * concurrent double-submit from the same instructor), revalidates
 * completion and participant ownership against the locked row (never
 * trusting a snapshot read before the lock), sanitizes every
 * observation with the same principles StudentReviewSubmitted uses,
 * and relies on the (lesson_id, instructor_id) unique index as the
 * final idempotency guarantee — a concurrent duplicate insert is
 * treated as the idempotent outcome, never a failure.
 */
final class SubmitInstructorStudentFeedbackAction
{
    private const string LOG_NAME = 'feedback';

    public function __construct(
        private readonly InstructorStudentFeedbackRepositoryInterface $feedback,
        private readonly AuditTrailService $audit,
    ) {}

    public function execute(Lesson $lesson, User $instructor, SubmitInstructorStudentFeedbackData $data): InstructorStudentFeedback
    {
        return DB::transaction(function () use ($lesson, $instructor, $data): InstructorStudentFeedback {
            $locked = Lesson::query()->whereKey($lesson->id)->lockForUpdate()->firstOrFail();

            $this->assertEligible($locked, $instructor);

            $existing = $this->feedback->findForLessonAndInstructor($locked, $instructor->id);

            if ($existing !== null) {
                return $existing;
            }

            $observations = $this->sanitizeAll($data);

            try {
                $created = $this->feedback->create([
                    'lesson_id' => $locked->id,
                    'booking_id' => $locked->booking_id,
                    'student_id' => $locked->student_id,
                    'instructor_id' => $instructor->id,
                    'outcome_snapshot' => $locked->outcome,
                    'source_outcome_version' => $locked->outcome_version,
                    'attendance_status_snapshot' => $locked->student_attendance_status,
                    'attendance_observation' => $observations['attendance_observation']->content,
                    'preparedness_observation' => $observations['preparedness_observation']->content,
                    'homework_completion_observation' => $observations['homework_completion_observation']->content,
                    'engagement_observation' => $observations['engagement_observation']->content,
                    'learning_attitude_observation' => $observations['learning_attitude_observation']->content,
                    'areas_needing_support' => $observations['areas_needing_support']->content,
                    'private_notes' => $observations['private_notes']->content,
                    'sanitization_metadata' => $this->flagSummary($observations),
                    'submitted_at' => now(),
                    'version' => 1,
                ]);
            } catch (UniqueConstraintViolationException) {
                // A concurrent request won the insert — that IS the
                // idempotent outcome, not a transient failure.
                return $this->feedback->findForLessonAndInstructor($locked, $instructor->id)
                    ?? throw new InstructorStudentFeedbackException('Feedback submission could not be completed.');
            }

            $this->audit->logUser(
                $instructor,
                self::LOG_NAME,
                'instructor_student_feedback_submitted',
                sprintf('Instructor feedback submitted for lesson %s.', $locked->id),
                $created,
                [
                    'lesson_id' => $locked->id,
                    'booking_id' => $locked->booking_id,
                    'student_id' => $locked->student_id,
                    'instructor_id' => $instructor->id,
                    'source_outcome' => $locked->outcome->value,
                    'source_outcome_version' => $locked->outcome_version,
                    'sanitization_flag_categories' => $this->flagSummary($observations),
                ],
            );

            InstructorStudentFeedbackSubmitted::dispatch($created);

            return $created;
        });
    }

    private function assertEligible(Lesson $lesson, User $instructor): void
    {
        if ($lesson->instructor_id !== $instructor->id) {
            throw new InstructorStudentFeedbackException('You are not the assigned instructor for this lesson.');
        }

        if (! $instructor->isActive() || ! $instructor->hasRole('instructor')) {
            throw new InstructorStudentFeedbackException('Your instructor account is not authorized to teach.');
        }

        if ($lesson->outcome !== LessonOutcome::Completed || ! $lesson->hasFinalizedOutcome()) {
            throw new InstructorStudentFeedbackException('Feedback can only be submitted after the lesson is completed.');
        }

        $booking = $lesson->booking;

        if ($booking === null || $booking->status === BookingStatus::Cancelled) {
            throw new InstructorStudentFeedbackException('This booking is not valid for feedback.');
        }

        if ($lesson->student_id === null
            || $lesson->student_id !== $booking->attendee_id
            || $lesson->instructor_id !== $booking->host_id) {
            throw new InstructorStudentFeedbackException('This lesson\'s participants do not match its booking.');
        }
    }

    /** @return array<string, SanitizedReviewContent> */
    private function sanitizeAll(SubmitInstructorStudentFeedbackData $data): array
    {
        return [
            'attendance_observation' => ReviewContentSanitizer::sanitize($data->attendanceObservation),
            'preparedness_observation' => ReviewContentSanitizer::sanitize($data->preparednessObservation),
            'homework_completion_observation' => ReviewContentSanitizer::sanitize($data->homeworkCompletionObservation),
            'engagement_observation' => ReviewContentSanitizer::sanitize($data->engagementObservation),
            'learning_attitude_observation' => ReviewContentSanitizer::sanitize($data->learningAttitudeObservation),
            'areas_needing_support' => ReviewContentSanitizer::sanitize($data->areasNeedingSupport),
            'private_notes' => ReviewContentSanitizer::sanitize($data->privateNotes),
        ];
    }

    /**
     * @param  array<string, SanitizedReviewContent>  $observations
     * @return array<string, list<string>> flag categories only, keyed by field — never raw text
     */
    private function flagSummary(array $observations): array
    {
        return collect($observations)
            ->map(fn (SanitizedReviewContent $sanitized): array => array_map(fn ($flag): string => $flag->value, $sanitized->flags))
            ->filter(fn (array $flags): bool => $flags !== [])
            ->all();
    }
}
