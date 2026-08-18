<?php

declare(strict_types=1);

namespace App\Lessons\Summaries\Repositories;

use App\Lessons\Summaries\Contracts\LessonSummaryRepositoryInterface;
use App\Models\Lesson;
use App\Models\LessonAiSummary;

final class LessonSummaryRepository implements LessonSummaryRepositoryInterface
{
    public function create(array $attributes): LessonAiSummary
    {
        return LessonAiSummary::query()->create($attributes);
    }

    public function update(LessonAiSummary $summary, array $attributes): LessonAiSummary
    {
        $summary->fill($attributes)->save();

        return $summary;
    }

    public function find(string $id): ?LessonAiSummary
    {
        return LessonAiSummary::query()->find($id);
    }

    public function forLesson(Lesson $lesson): ?LessonAiSummary
    {
        return LessonAiSummary::query()
            ->where('lesson_id', $lesson->getKey())
            ->first();
    }
}
