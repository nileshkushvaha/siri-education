<?php

declare(strict_types=1);

namespace App\Reviews\Repositories;

use App\Models\LessonReview;
use App\Models\LessonReviewEligibility;
use App\Reviews\Contracts\LessonReviewRepositoryInterface;

final class LessonReviewRepository implements LessonReviewRepositoryInterface
{
    public function findForEligibility(LessonReviewEligibility $eligibility): ?LessonReview
    {
        return LessonReview::query()->where('eligibility_id', $eligibility->id)->first();
    }

    public function create(array $attributes): LessonReview
    {
        return LessonReview::query()->create($attributes);
    }

    public function lock(LessonReview $review): LessonReview
    {
        return LessonReview::query()
            ->whereKey($review->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }
}
