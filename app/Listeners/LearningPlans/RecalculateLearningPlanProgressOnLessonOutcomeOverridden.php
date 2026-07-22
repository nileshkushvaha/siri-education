<?php

declare(strict_types=1);

namespace App\Listeners\LearningPlans;

use App\Lessons\Events\LessonOutcomeOverridden;
use App\Services\Student\LearningPlanProgressService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Thin trigger — mirrors RecalculateLearningPlanProgressOnLessonOutcomeFinalized
 * for the admin-correction path. An override only ever affects the one
 * plan the lesson is (or was, before the override) already linked to —
 * the link itself never changes here, only the outcome value the
 * calculator reads.
 */
final class RecalculateLearningPlanProgressOnLessonOutcomeOverridden implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly LearningPlanProgressService $progress,
    ) {}

    public function handle(LessonOutcomeOverridden $event): void
    {
        $this->progress->recalculate($event->lesson->learningPlan, null);
    }
}
