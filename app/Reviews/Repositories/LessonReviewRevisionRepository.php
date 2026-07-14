<?php

declare(strict_types=1);

namespace App\Reviews\Repositories;

use App\Models\LessonReview;
use App\Models\LessonReviewRevision;
use App\Reviews\Contracts\LessonReviewRevisionRepositoryInterface;

/** Append-only — no update or delete method exists by design. */
final class LessonReviewRevisionRepository implements LessonReviewRevisionRepositoryInterface
{
    public function append(array $attributes): LessonReviewRevision
    {
        return LessonReviewRevision::query()->create($attributes);
    }

    public function countForReview(LessonReview $review): int
    {
        return LessonReviewRevision::query()
            ->where('lesson_review_id', $review->id)
            ->count();
    }
}
