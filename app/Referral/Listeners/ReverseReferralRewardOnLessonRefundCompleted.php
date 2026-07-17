<?php

declare(strict_types=1);

namespace App\Referral\Listeners;

use App\Referral\Contracts\ReferralRewardServiceInterface;
use App\Wallet\Events\LessonRefundCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The student's lesson was refunded to their wallet — the referrer's
 * reward for that lesson no longer stands (SRS 16.11 "Payment is not
 * refunded"). The disposition's resolver (an admin, when the refund
 * required a human decision) doubles as the reversal actor; automated
 * refunds without one park a credited reward in reversal_required for
 * Phase 19E. Idempotent under the duplicate delivery this event's own
 * ledger idempotency already makes rare.
 */
final class ReverseReferralRewardOnLessonRefundCompleted implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly ReferralRewardServiceInterface $rewards,
    ) {}

    public function handle(LessonRefundCompleted $event): void
    {
        $lesson = $event->disposition->lesson;

        if ($lesson === null) {
            return;
        }

        $this->rewards->reevaluateLesson(
            $lesson,
            $event->disposition->resolver,
            'lesson_refunded',
        );
    }
}
