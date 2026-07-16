<?php

declare(strict_types=1);

namespace App\Listeners\Earnings;

use App\Earnings\Contracts\InstructorEarningRepositoryInterface;
use App\Earnings\Contracts\InstructorEarningServiceInterface;
use App\Earnings\Enums\InstructorEarningStatus;
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

        // DisputedHold is not terminal (a hold is later resolved back to
        // Releasable, or on to Reversed/Cancelled), so isTerminal() alone
        // does not catch a redelivered event for an earning already on
        // hold — without this explicit check, a duplicate queue delivery
        // would attempt a DisputedHold -> DisputedHold transition, which
        // is not in InstructorEarningStatus::allowedTransitions() and
        // throws instead of no-opping. Mirrors the identical guard in
        // LessonFinancialDispositionService::holdEarning().
        if ($earning === null || $earning->status->isTerminal() || $earning->status === InstructorEarningStatus::DisputedHold) {
            return;
        }

        $this->earnings->holdForDispute($earning);
    }
}
