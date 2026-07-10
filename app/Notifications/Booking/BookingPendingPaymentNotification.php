<?php

declare(strict_types=1);

namespace App\Notifications\Booking;

use App\Models\Booking;
use App\Notifications\Booking\Concerns\RoutesBookingChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the student only, when a paid booking reserves its slot and
 * awaits payment. The amount shown is the booking's own price snapshot
 * — already student-visible on the checkout page.
 */
final class BookingPendingPaymentNotification extends BookingNotification
{
    use Queueable, RoutesBookingChannels, SerializesModels;

    public function __construct(
        public readonly Booking $booking,
    ) {
        $this->onQueue('notifications');
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->configureMailMessage(new MailMessage)
            ->subject(sprintf('Complete your payment for booking %s', $this->booking->reference))
            ->line(sprintf(
                'Your %s on %s (%s) is reserved and awaiting payment.',
                $this->booking->type->name,
                $this->booking->starts_at->timezone($this->booking->timezone)->format('D, M j Y \a\t H:i'),
                $this->booking->timezone,
            ))
            ->line(sprintf('Amount due: %s %s', $this->booking->currency, $this->booking->price))
            ->action('Complete payment', route('dashboard.my-bookings'))
            ->line('The reservation is released automatically if payment is not completed in time.')
            ->line(sprintf('Reference: %s', $this->booking->reference));
    }

    protected function plainText(): string
    {
        return sprintf(
            'Booking %s reserved — payment of %s %s pending for %s on %s (%s).',
            $this->booking->reference,
            $this->booking->currency,
            $this->booking->price,
            $this->booking->type->name,
            $this->booking->starts_at->timezone($this->booking->timezone)->format('D, M j Y H:i'),
            $this->booking->timezone,
        );
    }
}
