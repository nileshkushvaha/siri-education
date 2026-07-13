<?php

declare(strict_types=1);

namespace App\Reviews\Contracts;

use App\Models\LessonReview;
use App\Models\ReviewRatingContribution;

interface ReviewRatingContributionRepositoryInterface
{
    /** Row-locked fetch, creating an (uncontributing) row first if none exists. Call only inside a transaction. */
    public function lockOrCreateForReview(LessonReview $review): ReviewRatingContribution;
}
