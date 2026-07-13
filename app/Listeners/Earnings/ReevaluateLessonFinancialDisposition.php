<?php

declare(strict_types=1);

namespace App\Listeners\Earnings;

use App\Earnings\Contracts\LessonFinancialDispositionServiceInterface;
use App\Lessons\Events\LessonOutcomeOverridden;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Phase 17E: an admin outcome override re-opens the financial decision.
 * The service preserves the previous disposition in history, holds any
 * unsettled earning affected by the correction, and routes settled or
 * already-refunded conflicts to manual review — never moving money.
 */
final class ReevaluateLessonFinancialDisposition implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly LessonFinancialDispositionServiceInterface $dispositions,
    ) {}

    public function handle(LessonOutcomeOverridden $event): void
    {
        $this->dispositions->reevaluate($event->lesson, $event->previousOutcome, $event->outcome, $event->reason);
    }
}
