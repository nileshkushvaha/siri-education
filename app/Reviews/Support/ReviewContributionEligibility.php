<?php

declare(strict_types=1);

namespace App\Reviews\Support;

use App\Models\LessonReview;
use App\Reviews\Enums\LessonReviewEligibilityMode;
use App\Reviews\Enums\StudentReviewStatus;

/**
 * The single inclusion predicate — used by both the event-driven
 * incremental reconciler and the full rebuild, so they can never
 * disagree about which reviews qualify. Reads only the review's own
 * stored state, never current settings: a review's historical
 * rating-scale snapshot governs its own validity forever.
 */
final class ReviewContributionEligibility
{
    public static function qualifies(LessonReview $review): bool
    {
        if ($review->status !== StudentReviewStatus::Published) {
            return false;
        }

        if ($review->review_mode !== LessonReviewEligibilityMode::PublicReview) {
            return false;
        }

        if ($review->overall_rating === null) {
            return false;
        }

        $min = $review->settings_snapshot['rating_min'] ?? null;
        $max = $review->settings_snapshot['rating_max'] ?? null;

        if ($min !== null && $review->overall_rating < $min) {
            return false;
        }

        if ($max !== null && $review->overall_rating > $max) {
            return false;
        }

        return $review->eligibility_id !== null && $review->lesson_id !== null;
    }
}
