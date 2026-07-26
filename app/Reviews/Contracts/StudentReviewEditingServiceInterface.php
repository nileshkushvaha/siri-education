<?php

declare(strict_types=1);

namespace App\Reviews\Contracts;

use App\Models\LessonReview;
use App\Models\User;
use App\Reviews\DTOs\EditStudentReviewData;
use App\Reviews\DTOs\ReviewEditability;
use App\Reviews\Exceptions\ReviewEligibilityException;
use App\Reviews\Exceptions\ReviewValidationException;

/**
 * Single entry point for limited student review editing. The caller
 * passes auth()->user(), never a request-supplied id;
 * ownership, window, status, and report locks are all revalidated
 * under lock inside EditStudentReviewAction regardless of what the
 * rendering page believed.
 */
interface StudentReviewEditingServiceInterface
{
    /**
     * @throws ReviewEligibilityException
     * @throws ReviewValidationException
     */
    public function edit(LessonReview $review, User $student, EditStudentReviewData $data): LessonReview;

    /** UI-safe editability (deadline when allowed, neutral reason when not). */
    public function editabilityFor(LessonReview $review, User $student): ReviewEditability;

    /** Safe history summary — a count only, never revision content. */
    public function revisionCountFor(LessonReview $review): int;
}
