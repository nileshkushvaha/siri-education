<?php

declare(strict_types=1);

namespace App\Listeners\Earnings;

use App\Earnings\Contracts\InstructorEarningRepositoryInterface;
use App\Earnings\Contracts\InstructorEarningServiceInterface;
use App\Earnings\Exceptions\EarningException;
use App\Lessons\Events\LessonCancelled;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A lesson cancelled after completion (dispute resolved against the
 * instructor) takes its unsettled earning with it. Settled earnings
 * are left alone — clawback is future manual work.
 */
final class ReverseEarningOnLessonCancelled implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly InstructorEarningRepositoryInterface $repository,
        private readonly InstructorEarningServiceInterface $earnings,
    ) {}

    public function handle(LessonCancelled $event): void
    {
        $earning = $this->repository->findForLesson($event->lesson);

        if ($earning === null || $earning->status->isTerminal()) {
            return;
        }

        try {
            $this->earnings->reverse($earning, reason: 'Lesson was cancelled.');
        } catch (EarningException) {
            // Assigned to an open batch — the admin must cancel the batch
            // first; the audit trail already points there.
        }
    }
}
