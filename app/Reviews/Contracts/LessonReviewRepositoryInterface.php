<?php

declare(strict_types=1);

namespace App\Reviews\Contracts;

use App\Models\LessonReview;
use App\Models\LessonReviewEligibility;

interface LessonReviewRepositoryInterface
{
    public function findForEligibility(LessonReviewEligibility $eligibility): ?LessonReview;

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): LessonReview;
}
