<?php

declare(strict_types=1);

namespace App\Reviews\Contracts;

use App\Models\Lesson;
use App\Models\LessonReviewEligibility;
use Carbon\CarbonInterface;
use Illuminate\Support\LazyCollection;

interface LessonReviewEligibilityRepositoryInterface
{
    public function findForLessonAndStudent(Lesson $lesson, int $studentId): ?LessonReviewEligibility;

    /** Refetch with a row lock — call only inside a transaction. */
    public function lock(LessonReviewEligibility $eligibility): LessonReviewEligibility;

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): LessonReviewEligibility;

    /**
     * Open eligibility whose window has passed, ordered deterministically
     * and cursored in chunks — never the full set in memory.
     *
     * @return LazyCollection<int, LessonReviewEligibility>
     */
    public function dueForExpiration(CarbonInterface $now, int $chunkSize): LazyCollection;
}
