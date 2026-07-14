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

    /**
     * How many of the instructor's eligible published public reviews
     * selected each configured tag — cursored over only the `tags`
     * column (never a full review fetch), never private feedback.
     *
     * @return array<string, array{label: string, count: int}> keyed by tag key
     */
    public function tagCountsForInstructor(int $instructorId): array;
}
