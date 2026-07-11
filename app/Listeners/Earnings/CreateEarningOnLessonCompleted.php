<?php

declare(strict_types=1);

namespace App\Listeners\Earnings;

use App\Earnings\Contracts\InstructorEarningServiceInterface;
use App\Lessons\Events\LessonCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The only automatic earning-creation trigger: LessonCompleted fires
 * from LessonLifecycleService (manual, admin, and auto completion) —
 * never from payment success, booking confirmation, meeting creation,
 * or a frontend action. InstructorEarningService re-checks eligibility
 * and idempotency, so this is a thin trigger, not a decision point.
 */
final class CreateEarningOnLessonCompleted implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly InstructorEarningServiceInterface $earnings,
    ) {}

    public function handle(LessonCompleted $event): void
    {
        $this->earnings->createFromLesson($event->lesson);
    }
}
