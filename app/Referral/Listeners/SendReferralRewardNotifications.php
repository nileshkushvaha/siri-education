<?php

declare(strict_types=1);

namespace App\Referral\Listeners;

use App\Models\ReferralReward;
use App\Notifications\Referral\ReferralRewardCreditedNotification;
use App\Notifications\Referral\ReferralRewardHeldNotification;
use App\Notifications\Referral\ReferralRewardReversedNotification;
use App\Referral\Events\ReferralRewardCredited;
use App\Referral\Events\ReferralRewardHeld;
use App\Referral\Events\ReferralRewardReversed;
use App\Referral\Support\ReferredStudentMask;
use App\Services\Notifications\NotificationIdempotencyGuard;
use App\Support\MoneyFormatter;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Referrer-facing reward notifications (SRS 16.27/16.33): credited,
 * held (neutral wording), reversed. Queued and idempotent — a redelivered
 * event never re-sends; a notification failure never touches reward or
 * wallet state (money moved in a transaction that already committed).
 * Identity is masked through the single ReferredStudentMask rule.
 */
final class SendReferralRewardNotifications implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly NotificationIdempotencyGuard $idempotency,
    ) {}

    public function handleCredited(ReferralRewardCredited $event): void
    {
        $reward = ReferralReward::query()->with(['referrer', 'referredStudent'])->find($event->rewardId);

        if ($reward?->referrer === null) {
            return;
        }

        $this->idempotency->once(
            sprintf('referral-reward-credited:%d', $reward->id),
            ReferralRewardCreditedNotification::class,
            fn () => $reward->referrer->notify(new ReferralRewardCreditedNotification(
                $reward->id,
                ReferredStudentMask::mask($reward->referredStudent),
                MoneyFormatter::format($reward->reward_amount_minor, $reward->reward_currency_code),
            )),
        );
    }

    public function handleHeld(ReferralRewardHeld $event): void
    {
        $reward = ReferralReward::query()->with('referrer')->find($event->rewardId);

        if ($reward?->referrer === null) {
            return;
        }

        $this->idempotency->once(
            sprintf('referral-reward-held:%d', $reward->id),
            ReferralRewardHeldNotification::class,
            fn () => $reward->referrer->notify(new ReferralRewardHeldNotification($reward->id)),
        );
    }

    public function handleReversed(ReferralRewardReversed $event): void
    {
        $reward = ReferralReward::query()->with('referrer')->find($event->rewardId);

        if ($reward?->referrer === null) {
            return;
        }

        $this->idempotency->once(
            sprintf('referral-reward-reversed:%d', $reward->id),
            ReferralRewardReversedNotification::class,
            fn () => $reward->referrer->notify(new ReferralRewardReversedNotification(
                $reward->id,
                MoneyFormatter::format($reward->reward_amount_minor, $reward->reward_currency_code),
            )),
        );
    }
}
