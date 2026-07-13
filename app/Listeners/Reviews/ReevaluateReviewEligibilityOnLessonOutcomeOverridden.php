<?php

declare(strict_types=1);

namespace App\Listeners\Reviews;

use App\Lessons\Events\LessonOutcomeOverridden;
use App\Reviews\Contracts\ReviewEligibilityServiceInterface;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Thin trigger — all correction logic lives in ReviewEligibilityService. */
final class ReevaluateReviewEligibilityOnLessonOutcomeOverridden implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly ReviewEligibilityServiceInterface $eligibility,
    ) {}

    public function handle(LessonOutcomeOverridden $event): void
    {
        $this->eligibility->handleOutcomeOverridden($event->lesson, $event->previousOutcome, $event->outcome, $event->reason);
    }
}
