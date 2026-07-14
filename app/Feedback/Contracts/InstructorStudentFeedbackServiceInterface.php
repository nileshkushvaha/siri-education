<?php

declare(strict_types=1);

namespace App\Feedback\Contracts;

use App\Feedback\DTOs\InstructorStudentFeedbackData;
use App\Feedback\DTOs\SubmitInstructorStudentFeedbackData;
use App\Feedback\Exceptions\InstructorStudentFeedbackException;
use App\Models\InstructorStudentFeedback;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Single entry point for instructor-to-student feedback. Never
 * accepts a bare instructor id from a caller — the controller/Livewire
 * layer is responsible for passing auth()->user(), never a
 * request-supplied id.
 */
interface InstructorStudentFeedbackServiceInterface
{
    /**
     * @throws AuthorizationException
     * @throws InstructorStudentFeedbackException
     */
    public function submit(Lesson $lesson, User $instructor, SubmitInstructorStudentFeedbackData $data): InstructorStudentFeedbackData;

    /** Null when the instructor has not yet submitted feedback for this lesson. */
    public function forLessonAndInstructor(Lesson $lesson, User $instructor): ?InstructorStudentFeedbackData;

    /**
     * View a specific feedback record — the submitting instructor or a
     * permissioned administrator only (InstructorStudentFeedbackPolicy).
     * The essential read path for staff, without a dedicated admin/
     * Filament UI in this phase.
     *
     * @throws AuthorizationException
     */
    public function view(InstructorStudentFeedback $feedback, User $viewer): InstructorStudentFeedbackData;

    /**
     * Batch existence lookup for a list of the instructor's own lessons
     * (avoids N+1 on a paginated lesson list). Silently excludes any
     * lesson id that is not owned by $instructor rather than throwing.
     *
     * @param  list<string>  $lessonIds
     * @return array<string, InstructorStudentFeedbackData> keyed by lesson id
     */
    public function existingForLessons(array $lessonIds, User $instructor): array;
}
