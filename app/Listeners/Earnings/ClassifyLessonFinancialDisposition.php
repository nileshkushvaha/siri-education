<?php

declare(strict_types=1);

namespace App\Listeners\Earnings;

use App\Earnings\Contracts\LessonFinancialDispositionServiceInterface;
use App\Lessons\Events\LessonOutcomeFinalized;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Phase 17E: classifies the financial disposition exactly once per
 * finalized lesson outcome. A thin trigger — the service is gated by
 * instructor_earnings.financial_disposition_enabled, idempotent, and
 * never moves money. Deliberately separate from
 * CreateEarningOnLessonCompleted, which keeps sole ownership of
 * completed-lesson earning creation.
 */
final class ClassifyLessonFinancialDisposition implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly LessonFinancialDispositionServiceInterface $dispositions,
    ) {}

    public function handle(LessonOutcomeFinalized $event): void
    {
        $this->dispositions->classify($event->lesson, $event->outcome);
    }
}
