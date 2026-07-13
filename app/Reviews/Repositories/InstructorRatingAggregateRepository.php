<?php

declare(strict_types=1);

namespace App\Reviews\Repositories;

use App\Models\InstructorRatingAggregate;
use App\Models\LessonReview;
use App\Reviews\Contracts\InstructorRatingAggregateRepositoryInterface;
use Illuminate\Support\Collection;

final class InstructorRatingAggregateRepository implements InstructorRatingAggregateRepositoryInterface
{
    public function findForInstructor(int $instructorId): ?InstructorRatingAggregate
    {
        return InstructorRatingAggregate::query()->where('instructor_id', $instructorId)->first();
    }

    public function lockOrCreateForInstructor(int $instructorId): InstructorRatingAggregate
    {
        // firstOrCreate before the locked refetch: the unique
        // instructor_id index makes a concurrent double-create
        // impossible, and the lockForUpdate read is authoritative either way.
        InstructorRatingAggregate::query()->firstOrCreate(['instructor_id' => $instructorId]);

        return InstructorRatingAggregate::query()
            ->where('instructor_id', $instructorId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function instructorIdsWithReviews(): Collection
    {
        return LessonReview::query()
            ->distinct()
            ->orderBy('instructor_id')
            ->pluck('instructor_id');
    }
}
