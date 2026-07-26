<?php

declare(strict_types=1);

namespace App\Notifications\Referral;

use App\Notifications\Templates\NotificationTemplateChannel;
use App\Notifications\Templates\NotificationTemplateKey;
use App\Notifications\Templates\NotificationTemplateRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;

final class ReferralRewardCreditedNotification extends ReferralNotification
{
    use Queueable;

    public function __construct(
        public readonly int $rewardId,
        public readonly string $maskedReferredName,
        public readonly string $formattedAmount,
    ) {
        $this->onQueue('notifications');
    }

    public function toMail(object $notifiable): MailMessage
    {
        $rendered = app(NotificationTemplateRenderer::class)->render(
            NotificationTemplateKey::ReferralRewardCredited,
            NotificationTemplateChannel::Mail,
            ['amount' => $this->formattedAmount, 'referred_name' => $this->maskedReferredName],
        );

        $mail = $this->configureMailMessage(new MailMessage)->subject($rendered->subject);

        foreach ($rendered->lines as $line) {
            $mail->line($line);
        }

        return $mail->action('View your wallet', route('dashboard.wallet'));
    }

    protected function plainText(): string
    {
        return sprintf(
            '%s has been credited to your wallet — %s completed an eligible class. Thanks for spreading the word!',
            $this->formattedAmount,
            $this->maskedReferredName,
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
