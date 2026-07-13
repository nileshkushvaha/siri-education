<?php

declare(strict_types=1);

namespace App\Reviews\Repositories;

use App\Models\Lesson;
use App\Models\LessonReviewEligibility;
use App\Reviews\Contracts\LessonReviewEligibilityRepositoryInterface;
use App\Reviews\Enums\LessonReviewEligibilityStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\LazyCollection;

final class LessonReviewEligibilityRepository implements LessonReviewEligibilityRepositoryInterface
{
    public function findForLessonAndStudent(Lesson $lesson, int $studentId): ?LessonReviewEligibility
    {
        return LessonReviewEligibility::query()
            ->where('lesson_id', $lesson->id)
            ->where('student_id', $studentId)
            ->first();
    }

    public function lock(LessonReviewEligibility $eligibility): LessonReviewEligibility
    {
        return LessonReviewEligibility::query()
            ->whereKey($eligibility->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function create(array $attributes): LessonReviewEligibility
    {
        return LessonReviewEligibility::query()->create($attributes);
    }

    public function dueForExpiration(CarbonInterface $now, int $chunkSize): LazyCollection
    {
        return LessonReviewEligibility::query()
            ->where('status', LessonReviewEligibilityStatus::Open)
            ->where('expires_at', '<=', $now)
            ->lazyById(max(1, $chunkSize));
    }
}
