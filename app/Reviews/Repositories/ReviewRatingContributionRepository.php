<?php

declare(strict_types=1);

namespace App\Reviews\Repositories;

use App\Models\LessonReview;
use App\Models\ReviewRatingContribution;
use App\Reviews\Contracts\ReviewRatingContributionRepositoryInterface;

final class ReviewRatingContributionRepository implements ReviewRatingContributionRepositoryInterface
{
    public function lockOrCreateForReview(LessonReview $review): ReviewRatingContribution
    {
        // firstOrCreate before the locked refetch: the unique review_id
        // index makes a concurrent double-create impossible, and the
        // lockForUpdate read is authoritative either way.
        ReviewRatingContribution::query()->firstOrCreate(
            ['review_id' => $review->id],
            ['instructor_id' => $review->instructor_id],
        );

        return ReviewRatingContribution::query()
            ->where('review_id', $review->id)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
