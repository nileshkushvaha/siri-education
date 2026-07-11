<?php

declare(strict_types=1);

namespace App\Listeners\Earnings;

use App\Earnings\Contracts\InstructorEarningRepositoryInterface;
use App\Earnings\Contracts\InstructorEarningServiceInterface;
use App\Lessons\Events\LessonDisputed;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A dispute parks the (unsettled) earning: pending_hold/releasable →
 * disputed_hold, detaching it from any open batch. Already-settled
 * money is out of scope (clawback is a future, manual concern) — the
 * transition guard makes that a safe no-op here.
 */
final class SyncEarningOnLessonDisputed implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly InstructorEarningRepositoryInterface $repository,
        private readonly InstructorEarningServiceInterface $earnings,
    ) {}

    public function handle(LessonDisputed $event): void
    {
        $earning = $this->repository->findForLesson($event->lesson);

        if ($earning === null || $earning->status->isTerminal()) {
            return;
        }

        $this->earnings->holdForDispute($earning);
    }
}
