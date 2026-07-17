<?php

declare(strict_types=1);

namespace App\Notifications\Referral;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Deliberately neutral wording (SRS 16.32): never the hold reason,
 * never "fraud", never anything about the referred student's activity.
 */
final class ReferralRewardHeldNotification extends ReferralNotification
{
    use Queueable;

    public function __construct(
        public readonly int $rewardId,
    ) {
        $this->onQueue('notifications');
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->configureMailMessage(new MailMessage)
            ->subject('A referral reward is being reviewed')
            ->line($this->plainText())
            ->action('View referral status', route('dashboard.refer-a-friend'));
    }

    protected function plainText(): string
    {
        return 'One of your referral rewards is being reviewed. We will let you know as soon as the review completes — no action is needed from you.';
    }

    protected function databaseContext(): array
    {
        return [
            'reward_id' => $this->rewardId,
            'action_url' => route('dashboard.refer-a-friend'),
        ];
    }
}
