<?php

declare(strict_types=1);

namespace App\Reviews\Contracts;

use App\Models\InstructorRatingAggregate;
use Illuminate\Support\Collection;

interface InstructorRatingAggregateRepositoryInterface
{
    public function findForInstructor(int $instructorId): ?InstructorRatingAggregate;

    /** Row-locked fetch, creating an empty aggregate first if none exists. Call only inside a transaction. */
    public function lockOrCreateForInstructor(int $instructorId): InstructorRatingAggregate;

    /**
     * Every distinct instructor id with at least one review. Inherently
     * bounded by instructor count (not review count), so this is safe
     * to fetch eagerly — the per-instructor review set is what gets
     * cursored, inside RebuildInstructorRatingAggregateAction.
     *
     * @return Collection<int, int>
     */
    public function instructorIdsWithReviews(): Collection;
}
