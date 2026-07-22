<?php

declare(strict_types=1);

namespace App\Listeners\LearningPlans;

use App\Lessons\Events\LessonOutcomeFinalized;
use App\Services\Student\LearningPlanProgressService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Thin trigger — all recalculation logic lives in
 * LearningPlanProgressService. Queued and idempotent: a duplicate
 * delivery of the same finalization event recomputes the same value
 * and skips the write. No-ops safely when the lesson has no plan
 * association or the plan is missing/soft-deleted/not writable.
 */
final class RecalculateLearningPlanProgressOnLessonOutcomeFinalized implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly LearningPlanProgressService $progress,
    ) {}

    public function handle(LessonOutcomeFinalized $event): void
    {
        $this->progress->recalculate($event->lesson->learningPlan, null);
    }
}
