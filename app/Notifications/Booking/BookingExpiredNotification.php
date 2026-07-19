<?php

declare(strict_types=1);

namespace App\Notifications\Booking;

use App\Models\Booking;
use App\Notifications\Booking\Concerns\RoutesBookingChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the student only, when booking:release-expired releases a
 * paid reservation whose payment hold lapsed — a friendlier flavor of
 * the cancellation notice, with a path back to booking again.
 */
final class BookingExpiredNotification extends BookingNotification
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
            ->subject(sprintf('Booking %s expired', $this->booking->reference))
            ->line(sprintf(
                'Your reservation for the %s on %s (%s) expired because payment was not completed in time.',
                $this->booking->type->name,
                $this->booking->starts_at->timezone($timezone)->format('D, M j Y \a\t H:i'),
                $timezone,
            ))
            ->line('The slot has been released — you can book again at any time.')
            ->action('Book again', route('dashboard.my-bookings'))
            ->line(sprintf('Reference: %s', $this->booking->reference));
    }

    protected function plainText(object $notifiable): string
    {
        return sprintf(
            'Booking %s expired: payment was not completed in time. The slot has been released.',
            $this->booking->reference,
        );
    }
}
