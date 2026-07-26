<?php

declare(strict_types=1);

namespace App\Notifications\Wallet;

use App\Models\User;
use App\Models\WalletRecharge;
use App\Notifications\Templates\NotificationTemplateChannel;
use App\Notifications\Templates\NotificationTemplateKey;
use App\Notifications\Templates\NotificationTemplateRenderer;
use App\Support\MoneyFormatter;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the student only, after a provider-verified recharge
 * credits their wallet. Amount/currency/internal reference only —
 * never a payment provider order/payment id or gateway detail.
 */
final class WalletRechargeSucceededNotification extends WalletNotification
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly WalletRecharge $recharge,
    ) {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return $notifiable instanceof User ? ['mail', 'database'] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $rendered = app(NotificationTemplateRenderer::class)->render(
            NotificationTemplateKey::WalletRechargeSucceeded,
            NotificationTemplateChannel::Mail,
            ['amount' => $this->amountFormatted(), 'reference' => $this->recharge->idempotency_key],
        );

        $mail = $this->configureMailMessage(new MailMessage)->subject($rendered->subject);

        foreach ($rendered->lines as $line) {
            $mail->line($line);
        }

        return $mail->action('View my wallet', route('dashboard.wallet'));
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $rendered = app(NotificationTemplateRenderer::class)->render(
            NotificationTemplateKey::WalletRechargeSucceeded,
            NotificationTemplateChannel::Database,
            ['amount' => $this->amountFormatted()],
        );

        return [
            'title' => $rendered->subject,
            'message' => $rendered->message(),
            'wallet_recharge_reference' => $this->recharge->idempotency_key,
        ];
    }

    private function amountFormatted(): string
    {
        return MoneyFormatter::format($this->recharge->amount_minor, $this->recharge->currency_code);
    }
}
