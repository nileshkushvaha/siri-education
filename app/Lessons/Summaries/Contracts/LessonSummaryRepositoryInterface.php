<?php

declare(strict_types=1);

namespace App\Lessons\Summaries\Contracts;

use App\Models\Lesson;
use App\Models\LessonAiSummary;

interface LessonSummaryRepositoryInterface
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): LessonAiSummary;

    /** @param array<string, mixed> $attributes */
    public function update(LessonAiSummary $summary, array $attributes): LessonAiSummary;

    public function find(string $id): ?LessonAiSummary;

    /** The lesson's single summary row, whatever state it is in. */
    public function forLesson(Lesson $lesson): ?LessonAiSummary;
}
