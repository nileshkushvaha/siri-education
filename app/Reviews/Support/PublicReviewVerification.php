<?php

declare(strict_types=1);

namespace App\Reviews\Support;

use App\Lessons\Enums\LessonOutcome;
use App\Models\LessonReview;
use App\Reviews\Enums\LessonReviewEligibilityStatus;

/**
 * "Verified Lesson" is never a stored, client-controlled flag — it's
 * derived fresh from the review's own eligibility and lesson
 * relationships every time. `eligibility->status = Used` means a
 * review was submitted against a window opened for a genuinely
 * completed lesson; the live `lesson->outcome` check additionally
 * catches the case where an outcome correction later reclassified the
 * lesson away from Completed (which moves the eligibility to
 * ManualReview, not Used — see docs/reviews.md "Outcome overrides").
 */
final class PublicReviewVerification
{
    public static function isVerified(LessonReview $review): bool
    {
        return $review->eligibility?->status === LessonReviewEligibilityStatus::Used
            && $review->lesson?->outcome === LessonOutcome::Completed;
    }
}
