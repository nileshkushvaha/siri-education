<?php

declare(strict_types=1);

namespace App\Referral\Listeners;

use App\Lessons\Enums\LessonOutcome;
use App\Lessons\Events\LessonOutcomeFinalized;
use App\Referral\Contracts\ReferralEligibilityServiceInterface;
use App\Referral\Contracts\ReferralRewardServiceInterface;
use App\Referral\Enums\ReferralRewardStatus;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The ONLY automatic reward-evaluation trigger: the after-commit,
 * exactly-once lesson outcome (SRS 16.11) — never booking creation,
 * registration, payment initiation, meeting attendance, or a client
 * callback. Thin by design: the eligibility service re-runs every gate
 * and both unique indexes make duplicate delivery harmless.
 *
 * An immediate-timing reward is credited right here through the SAME
 * creditReward() path the scheduled sweep uses — creditReward() itself
 * rechecks status and readiness under lock, so this is a convenience
 * call, not a second credit implementation.
 */
final class EvaluateReferralRewardOnLessonOutcomeFinalized implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly ReferralEligibilityServiceInterface $eligibility,
        private readonly ReferralRewardServiceInterface $rewards,
    ) {}

    public function handle(LessonOutcomeFinalized $event): void
    {
        if ($event->outcome !== LessonOutcome::Completed) {
            return;
        }

        $reward = $this->eligibility->evaluateCompletedLesson($event->lesson);

        if ($reward !== null
            && $reward->status === ReferralRewardStatus::Eligible
            && $reward->credit_ready_at !== null
            && ! $reward->credit_ready_at->isFuture()) {
            $this->rewards->creditReward($reward);
        }
    }
}
