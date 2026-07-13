<?php

declare(strict_types=1);

namespace App\Reviews\Contracts;

use App\Models\LessonReview;
use App\Models\LessonReviewEligibility;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface LessonReviewRepositoryInterface
{
    public function findForEligibility(LessonReviewEligibility $eligibility): ?LessonReview;

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): LessonReview;

    /** Refetch with a row lock — call only inside a transaction. */
    public function lock(LessonReview $review): LessonReview;

    /**
     * Published, public-mode reviews for one instructor — newest
     * published first, deterministic secondary order by id, cursored
     * via LIMIT/OFFSET pagination (never the full set in memory).
     */
    public function publicPaginatedForInstructor(int $instructorId, int $perPage): LengthAwarePaginator;
}
