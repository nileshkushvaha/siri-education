<?php

declare(strict_types=1);

namespace App\Reviews\Contracts;

use App\Models\LessonReview;
use App\Models\LessonReviewRevision;

interface LessonReviewRevisionRepositoryInterface
{
    /** @param array<string, mixed> $attributes */
    public function append(array $attributes): LessonReviewRevision;

    public function countForReview(LessonReview $review): int;
}
