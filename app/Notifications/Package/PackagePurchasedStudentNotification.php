<?php

declare(strict_types=1);

namespace App\Notifications\Package;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\StudentPackageEntitlement;
use App\Models\StudentPackagePurchase;
use App\Notifications\Package\Concerns\RoutesPackageChannels;
use App\Support\MoneyFormatter;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the purchasing student only — the commercial, customer-facing
 * half of a settled package purchase, and the only one of the pair that
 * carries the receipt.
 *
 * ## Money
 *
 * Amount and currency come from the immutable StudentPackagePurchase
 * snapshot (whose `amount_minor`/`currency_code` the model itself
 * refuses to let anything update), formatted through MoneyFormatter so
 * the exponent comes from `currencies.minor_units`. Nothing here reads
 * the proposal's current price, the pricing engine, the student's
 * country, PaymentGatewaySettings, or a platform default currency —
 * a receipt must still say what was actually paid years later.
 *
 * ## Quantities
 *
 * Paid/bonus/total come from the activated entitlement, which took its
 * own snapshot from the proposal at settlement. The bonus lessons are
 * described as an included benefit — never as a discount or a partial
 * refund, which is not what the domain models them as.
 */
final class PackagePurchasedStudentNotification extends PackageNotification
{
    use Queueable, RoutesPackageChannels, SerializesModels;

    public function __construct(
        public readonly StudentPackagePurchase $purchase,
        public readonly Payment $payment,
        public readonly StudentPackageEntitlement $entitlement,
        public readonly ?Invoice $receipt = null,
    ) {
        $this->onQueue('notifications');
    }

    public function toMail(object $notifiable): MailMessage
    {
        $timezone = $this->recipientTimezone($notifiable);
        $proposal = $this->purchase->proposal;

        $mail = $this->configureMailMessage(new MailMessage)
            ->subject(sprintf('Your lesson package is active — %s', $this->purchase->reference))
            ->greeting('Payment received — your package is ready')
            ->line(sprintf(
                'Your lesson package with %s%s is now active and ready to book.',
                $proposal?->instructor?->name ?? 'your instructor',
                $proposal?->subject?->name !== null ? ' for '.$proposal->subject->name : '',
            ))
            ->line(sprintf('Paid lessons: %d', $this->entitlement->paid_quantity))
            ->line(sprintf('Bonus lessons included: %d', $this->entitlement->bonus_quantity))
            ->line(sprintf('Total lessons available: %d', $this->entitlement->total_quantity));

        if ($this->entitlement->expires_at !== null) {
            $mail->line(sprintf(
                'Valid until: %s',
                $this->entitlement->expires_at->timezone($timezone)->format('D, M j Y'),
            ));
        }

        $mail->line(sprintf('Total paid: %s', $this->formattedAmount()))
            ->line(sprintf('Package reference: %s', $this->purchase->reference))
            ->line(sprintf('Payment date: %s', ($this->payment->paid_at ?? $this->payment->created_at)->timezone($timezone)->format('D, M j Y \a\t H:i')))
            ->line(sprintf('Payment method: %s', ucfirst((string) $this->payment->provider)));

        // Receipt to the payer alone, behind InvoicePolicy::view().
        if ($this->receipt !== null) {
            $mail->action('Download receipt', route('dashboard.invoices.download', $this->receipt));
            $mail->line(sprintf('Receipt number: %s', $this->receipt->invoice_number));
        }

        return $mail;
    }

    protected function plainText(object $notifiable): string
    {
        return sprintf(
            'Package %s is active: %d lessons (%d paid + %d bonus). Total paid %s.',
            $this->purchase->reference,
            $this->entitlement->total_quantity,
            $this->entitlement->paid_quantity,
            $this->entitlement->bonus_quantity,
            $this->formattedAmount(),
        );
    }

    /** "24,000.00 INR" — exponent from the currency, integer math only. */
    private function formattedAmount(): string
    {
        return MoneyFormatter::format(
            (int) $this->purchase->amount_minor,
            (string) $this->purchase->currency_code,
        );
    }
}
