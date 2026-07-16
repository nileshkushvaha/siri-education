<?php

declare(strict_types=1);

namespace App\Listeners\Earnings;

use App\Earnings\Contracts\InstructorEarningServiceInterface;
use App\Earnings\Contracts\LessonFinancialDispositionServiceInterface;
use App\Lessons\Events\LessonCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The only automatic earning-creation trigger: LessonCompleted fires
 * from LessonLifecycleService (manual, admin, and auto completion) —
 * never from payment success, booking confirmation, meeting creation,
 * or a frontend action. InstructorEarningService re-checks eligibility
 * and idempotency, so this is a thin trigger, not a decision point.
 *
 * LessonOutcomeFinalized (disposition classification) and
 * LessonCompleted (earning creation) are both after-commit events
 * dispatched from the same finalize() transaction with no ordering
 * guarantee between them. classify() already links an existing
 * earning when it runs second; linkEarning() covers the reverse
 * ordering, when this listener runs first and no disposition exists
 * for it to find yet.
 */
final class CreateEarningOnLessonCompleted implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly InstructorEarningServiceInterface $earnings,
        private readonly LessonFinancialDispositionServiceInterface $dispositions,
    ) {}

    public function handle(LessonCompleted $event): void
    {
        $earning = $this->earnings->createFromLesson($event->lesson);

        if ($earning !== null) {
            $this->dispositions->linkEarning($event->lesson, $earning);
        }
    }
}
