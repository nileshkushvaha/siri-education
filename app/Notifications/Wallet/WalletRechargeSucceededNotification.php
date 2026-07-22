<?php

declare(strict_types=1);

namespace App\Notifications\Wallet;

use App\Models\User;
use App\Models\WalletRecharge;
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
        return $this->configureMailMessage(new MailMessage)
            ->subject('Wallet recharge successful')
            ->line(sprintf('Your wallet was recharged with %s.', $this->amountFormatted()))
            ->action('View my wallet', route('dashboard.wallet'))
            ->line(sprintf('Reference: %s', $this->recharge->idempotency_key));
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Wallet Recharge Successful',
            'message' => $this->plainText($notifiable),
            'wallet_recharge_reference' => $this->recharge->idempotency_key,
        ];
    }

    protected function plainText(object $notifiable): string
    {
        return sprintf('Your wallet was recharged with %s.', $this->amountFormatted());
    }

    private function amountFormatted(): string
    {
        return MoneyFormatter::format($this->recharge->amount_minor, $this->recharge->currency_code);
    }
}
