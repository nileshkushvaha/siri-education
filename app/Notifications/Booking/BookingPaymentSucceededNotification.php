<?php

declare(strict_types=1);

namespace App\Notifications\Booking;

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Invoice;
use App\Notifications\Booking\Concerns\RoutesBookingChannels;
use App\Support\MoneyFormatter;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the student (the payer) only — never the instructor, never
 * an admin. This is the one booking notification that carries the
 * receipt.
 *
 * ## Where the money comes from
 *
 * Amount and currency are read from the settled BookingPayment row —
 * the immutable record of what was actually charged — NOT from
 * `bookings.price`/`bookings.currency`, which this notification used
 * to read. That mattered: the booking columns are a mutable
 * commercial snapshot (`price` is a decimal cast, so it also formatted
 * as a bare decimal string with no currency-exponent awareness),
 * whereas the payment row is the authoritative historical fact. A
 * later price-matrix, country, gateway-settings, or platform-default
 * change now cannot alter what this receipt says.
 *
 * Formatting goes through MoneyFormatter, which takes the exponent
 * from `currencies.minor_units` — so a JPY payment renders without
 * decimals and a USD payment never renders with a rupee sign. No
 * division by 100 and no float ever touches the amount.
 */
final class BookingPaymentSucceededNotification extends BookingNotification
{
    use Queueable, RoutesBookingChannels, SerializesModels;

    public function __construct(
        public readonly Booking $booking,
        public readonly BookingPayment $payment,
        public readonly ?Invoice $receipt = null,
    ) {
        $this->onQueue('notifications');
    }

    public function toMail(object $notifiable): MailMessage
    {
        $timezone = $this->recipientTimezone($notifiable);

        $mail = $this->configureMailMessage(new MailMessage)
            ->subject(sprintf('Payment received for booking %s', $this->booking->reference))
            ->greeting('Payment received')
            ->line(sprintf(
                'We received your payment of %s for the %s with %s on %s (%s).',
                $this->formattedAmount(),
                $this->booking->type->name,
                $this->booking->instructor?->name ?? 'your instructor',
                $this->booking->starts_at->timezone($timezone)->format('D, M j Y \a\t H:i'),
                $timezone,
            ))
            ->line('Your lesson is confirmed and now appears in your bookings.')
            ->line(sprintf('Booking reference: %s', $this->booking->reference))
            ->line(sprintf('Payment reference: %s', $this->payment->idempotency_key))
            ->line(sprintf('Payment date: %s', ($this->payment->paid_at ?? $this->payment->created_at)->timezone($timezone)->format('D, M j Y \a\t H:i')))
            ->line(sprintf('Payment method: %s', $this->providerLabel()));

        // Only the payer is ever handed the receipt. The link resolves
        // through InvoicePolicy::view() on every request, so it is not a
        // guessable public document — a signed-out or unrelated user
        // following it is refused.
        if ($this->receipt !== null) {
            $mail->action('Download receipt', route('dashboard.invoices.download', $this->receipt));
            $mail->line(sprintf('Receipt number: %s', $this->receipt->invoice_number));
        } else {
            $mail->action('View my bookings', route('dashboard.my-bookings'));
        }

        return $mail;
    }

    protected function plainText(object $notifiable): string
    {
        return sprintf(
            'Payment of %s received for booking %s. Your lesson is confirmed.',
            $this->formattedAmount(),
            $this->booking->reference,
        );
    }

    /** "1,250.00 INR" — exponent from the currency, integer math only. */
    private function formattedAmount(): string
    {
        return MoneyFormatter::format(
            (int) $this->payment->amount_minor,
            (string) $this->payment->currency_code,
        );
    }

    /**
     * The provider name is presentation, not architecture — a Stripe
     * payment reaches this same notification and simply reads
     * "Stripe". No provider-specific mail class exists or should.
     */
    private function providerLabel(): string
    {
        return ucfirst((string) $this->payment->provider);
    }
}
