<?php

declare(strict_types=1);

namespace App\Feedback\DTOs;

/**
 * Raw instructor input for one feedback submission. Every field is an
 * optional short educational observation — never a numeric or
 * sentiment score, since the SRS defines feedback topics but no rating
 * scale. Sanitized by SubmitInstructorStudentFeedbackAction; this DTO
 * carries the input as given, unvalidated and unsanitized.
 */
final readonly class SubmitInstructorStudentFeedbackData
{
    public function __construct(
        public ?string $attendanceObservation = null,
        public ?string $preparednessObservation = null,
        public ?string $homeworkCompletionObservation = null,
        public ?string $engagementObservation = null,
        public ?string $learningAttitudeObservation = null,
        public ?string $areasNeedingSupport = null,
        public ?string $privateNotes = null,
    ) {}
}
