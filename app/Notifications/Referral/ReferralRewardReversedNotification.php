<?php

declare(strict_types=1);

namespace App\Notifications\Referral;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * SRS 16.16/16.28: a reversal is a separate wallet transaction the
 * referrer can see — the notification names the amount but never the
 * underlying lesson/refund details of the referred student.
 */
final class ReferralRewardReversedNotification extends ReferralNotification
{
    use Queueable;

    public function __construct(
        public readonly int $rewardId,
        public readonly string $formattedAmount,
    ) {
        $this->onQueue('notifications');
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->configureMailMessage(new MailMessage)
            ->subject('A referral reward was reversed')
            ->line($this->plainText())
            ->action('View your wallet', route('dashboard.wallet'));
    }

    protected function plainText(): string
    {
        return sprintf(
            'A referral reward of %s was reversed because the related class no longer qualifies. Your wallet statement shows the matching entry.',
            $this->formattedAmount,
        );
    }

    protected function databaseContext(): array
    {
        return [
            'reward_id' => $this->rewardId,
            'action_url' => route('dashboard.refer-a-friend'),
        ];
    }
}
