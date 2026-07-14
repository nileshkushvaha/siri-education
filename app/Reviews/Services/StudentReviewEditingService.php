<?php

declare(strict_types=1);

namespace App\Reviews\Services;

use App\Models\LessonReview;
use App\Models\User;
use App\Reviews\Actions\EditStudentReviewAction;
use App\Reviews\Contracts\LessonReviewRevisionRepositoryInterface;
use App\Reviews\Contracts\StudentReviewEditingServiceInterface;
use App\Reviews\DTOs\EditStudentReviewData;
use App\Reviews\DTOs\ReviewEditability;
use App\Reviews\Support\ReviewEditPolicy;

/**
 * Thin boundary over EditStudentReviewAction (which owns the atomic
 * edit mechanics) and ReviewEditPolicy (the single editability
 * predicate). Administrators never edit through here — they use the
 * existing ReviewModerationService actions.
 */
final class StudentReviewEditingService implements StudentReviewEditingServiceInterface
{
    public function __construct(
        private readonly EditStudentReviewAction $editAction,
        private readonly ReviewEditPolicy $editPolicy,
        private readonly LessonReviewRevisionRepositoryInterface $revisions,
    ) {}

    public function edit(LessonReview $review, User $student, EditStudentReviewData $data): LessonReview
    {
        return $this->editAction->execute($review, $student, $data);
    }

    public function editabilityFor(LessonReview $review, User $student): ReviewEditability
    {
        return $this->editPolicy->evaluate($review, $student);
    }

    public function revisionCountFor(LessonReview $review): int
    {
        return $this->revisions->countForReview($review);
    }
}
