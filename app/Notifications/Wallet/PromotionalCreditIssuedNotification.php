<?php

declare(strict_types=1);

namespace App\Notifications\Wallet;

use App\Models\PromotionalCreditIssuance;
use App\Support\MoneyFormatter;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\SerializesModels;

/**
 * SRS §16.33 "Promotional credit received". Sent to the student only,
 * after the wallet ledger credit is durably committed. Amount,
 * currency, and (for campaign credits) the campaign name only — never
 * internal admin identity or reason text, which stays admin-only.
 */
final class PromotionalCreditIssuedNotification extends WalletNotification
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly PromotionalCreditIssuance $issuance,
    ) {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->configureMailMessage(new MailMessage)
            ->subject('Promotional credit received')
            ->line(sprintf('Your wallet was credited with %s.', $this->amountFormatted()))
            ->when($this->issuance->campaign !== null, fn (MailMessage $mail) => $mail->line(sprintf('Campaign: %s', $this->issuance->campaign->name)))
            ->action('View my wallet', route('dashboard.wallet'));
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Promotional Credit Received',
            'message' => sprintf('Your wallet was credited with %s.', $this->amountFormatted()),
            'promotional_credit_issuance_id' => $this->issuance->id,
        ];
    }

    private function amountFormatted(): string
    {
        return MoneyFormatter::format($this->issuance->amount_minor, $this->issuance->currency_code);
    }
}
