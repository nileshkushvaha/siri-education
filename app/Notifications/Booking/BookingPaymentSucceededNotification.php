<?php

declare(strict_types=1);

namespace App\Notifications\Booking;

use App\Models\Booking;
use App\Notifications\Booking\Concerns\RoutesBookingChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the student only, after a provider-verified payment settles
 * the booking. Amount/currency are the student's own already-visible
 * price snapshot — never a payment provider id, gateway reference, or
 * wallet detail.
 */
final class BookingPaymentSucceededNotification extends BookingNotification
{
    use Queueable, RoutesBookingChannels, SerializesModels;

    public function __construct(
        public readonly Booking $booking,
    ) {
        $this->onQueue('notifications');
    }

    public function toMail(object $notifiable): MailMessage
    {
        $timezone = $this->recipientTimezone($notifiable);

        return $this->configureMailMessage(new MailMessage)
            ->subject(sprintf('Payment received for booking %s', $this->booking->reference))
            ->line(sprintf(
                'We received your payment of %s %s for the %s on %s (%s).',
                $this->booking->currency,
                $this->booking->price,
                $this->booking->type->name,
                $this->booking->starts_at->timezone($timezone)->format('D, M j Y \a\t H:i'),
                $timezone,
            ))
            ->action('View my bookings', route('dashboard.my-bookings'))
            ->line(sprintf('Reference: %s', $this->booking->reference));
    }

    protected function plainText(object $notifiable): string
    {
        return sprintf(
            'Payment of %s %s received for booking %s.',
            $this->booking->currency,
            $this->booking->price,
            $this->booking->reference,
        );
    }
}
