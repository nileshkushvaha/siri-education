<?php

declare(strict_types=1);

namespace App\Referral\Listeners;

use App\Lessons\Enums\LessonOutcome;
use App\Lessons\Events\LessonOutcomeOverridden;
use App\Referral\Contracts\ReferralEligibilityServiceInterface;
use App\Referral\Contracts\ReferralRewardServiceInterface;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * An admin corrected a finalized outcome. Away from Completed: the
 * lesson's reward (if any) is invalidated — rejected before credit,
 * reversed (or parked reversal_required) after. Toward Completed: the
 * lesson is evaluated exactly like a fresh finalization. The event
 * carries no acting admin, so a credited reward's wallet reversal
 * lands in the visible reversal_required state for Phase 19E rather
 * than being performed by a fabricated actor.
 */
final class ReevaluateReferralRewardOnLessonOutcomeOverridden implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly ReferralEligibilityServiceInterface $eligibility,
        private readonly ReferralRewardServiceInterface $rewards,
    ) {}

    public function handle(LessonOutcomeOverridden $event): void
    {
        if ($event->outcome === LessonOutcome::Completed) {
            $this->eligibility->evaluateCompletedLesson($event->lesson);

            return;
        }

        $this->rewards->reevaluateLesson(
            $event->lesson,
            null,
            'outcome_overridden_to_'.$event->outcome->value,
        );
    }
}
