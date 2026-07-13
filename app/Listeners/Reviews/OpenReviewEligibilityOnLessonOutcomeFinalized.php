<?php

declare(strict_types=1);

namespace App\Listeners\Reviews;

use App\Lessons\Events\LessonOutcomeFinalized;
use App\Reviews\Contracts\ReviewEligibilityServiceInterface;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Thin trigger — all eligibility logic lives in
 * ReviewEligibilityService. Queued and idempotent: a duplicate
 * delivery of the same finalization event creates no second record.
 */
final class OpenReviewEligibilityOnLessonOutcomeFinalized implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly ReviewEligibilityServiceInterface $eligibility,
    ) {}

    public function handle(LessonOutcomeFinalized $event): void
    {
        $this->eligibility->handleOutcomeFinalized($event->lesson, $event->outcome);
    }
}
