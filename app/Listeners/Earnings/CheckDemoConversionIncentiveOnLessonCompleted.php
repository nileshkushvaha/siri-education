<?php

declare(strict_types=1);

namespace App\Listeners\Earnings;

use App\Earnings\Services\DemoConversionIncentiveService;
use App\Lessons\Events\LessonCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * GAP-008 requirement #7 — the only automatic evaluation trigger,
 * mirroring CreateEarningOnLessonCompleted exactly (same event, same
 * queue, same retry shape): LessonCompleted fires from
 * LessonLifecycleService after every completion (manual, admin, auto).
 * DemoConversionIncentiveService re-checks eligibility and idempotency
 * itself — this is a thin trigger, not a decision point. Registered as
 * a sibling listener on the SAME event, never a new completion path.
 */
final class CheckDemoConversionIncentiveOnLessonCompleted implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly DemoConversionIncentiveService $incentive,
    ) {}

    public function handle(LessonCompleted $event): void
    {
        $this->incentive->evaluate($event->lesson);
    }
}
