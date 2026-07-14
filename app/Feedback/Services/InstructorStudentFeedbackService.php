<?php

declare(strict_types=1);

namespace App\Feedback\Services;

use App\Feedback\Actions\SubmitInstructorStudentFeedbackAction;
use App\Feedback\Contracts\InstructorStudentFeedbackRepositoryInterface;
use App\Feedback\Contracts\InstructorStudentFeedbackServiceInterface;
use App\Feedback\DTOs\InstructorStudentFeedbackData;
use App\Feedback\DTOs\SubmitInstructorStudentFeedbackData;
use App\Models\InstructorStudentFeedback;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Authorization boundary (the acting user must be the lesson's own
 * assigned instructor — enforced via InstructorStudentFeedbackPolicy)
 * plus a thin wrapper around SubmitInstructorStudentFeedbackAction,
 * which owns the atomic submission mechanics.
 */
final class InstructorStudentFeedbackService implements InstructorStudentFeedbackServiceInterface
{
    public function __construct(
        private readonly SubmitInstructorStudentFeedbackAction $submitAction,
        private readonly InstructorStudentFeedbackRepositoryInterface $repository,
    ) {}

    public function submit(Lesson $lesson, User $instructor, SubmitInstructorStudentFeedbackData $data): InstructorStudentFeedbackData
    {
        Gate::forUser($instructor)->authorize('submit', [InstructorStudentFeedback::class, $lesson]);

        $feedback = $this->submitAction->execute($lesson, $instructor, $data);

        return InstructorStudentFeedbackData::fromModel($feedback);
    }

    public function forLessonAndInstructor(Lesson $lesson, User $instructor): ?InstructorStudentFeedbackData
    {
        $feedback = $this->repository->findForLessonAndInstructor($lesson, $instructor->id);

        if ($feedback === null) {
            return null;
        }

        Gate::forUser($instructor)->authorize('view', $feedback);

        return InstructorStudentFeedbackData::fromModel($feedback);
    }

    public function view(InstructorStudentFeedback $feedback, User $viewer): InstructorStudentFeedbackData
    {
        Gate::forUser($viewer)->authorize('view', $feedback);

        return InstructorStudentFeedbackData::fromModel($feedback);
    }

    public function existingForLessons(array $lessonIds, User $instructor): array
    {
        return $this->repository
            ->findManyForLessonsAndInstructor($lessonIds, $instructor->id)
            ->map(fn (InstructorStudentFeedback $feedback): InstructorStudentFeedbackData => InstructorStudentFeedbackData::fromModel($feedback))
            ->all();
    }
}
