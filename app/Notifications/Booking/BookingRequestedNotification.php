<?php

declare(strict_types=1);

namespace App\Notifications\Booking;

use App\Models\Booking;
use App\Notifications\Booking\Concerns\RoutesBookingChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/** Sent to the host when a booking needs their approval. */
final class BookingRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable, RoutesBookingChannels, SerializesModels;

    public function __construct(
        public readonly Booking $booking,
    ) {
        $this->onQueue('notifications');
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(sprintf('New booking request %s', $this->booking->reference))
            ->line(sprintf(
                'You have a new %s request from %s for %s (%s).',
                $this->booking->type->name,
                $this->booking->attendeeName() ?? 'a guest',
                $this->booking->starts_at->timezone($this->booking->timezone)->format('D, M j Y \a\t H:i'),
                $this->booking->timezone,
            ))
            ->line('Please confirm or decline this booking.')
            ->line(sprintf('Reference: %s', $this->booking->reference));
    }

    protected function plainText(): string
    {
        return sprintf(
            'New %s request %s from %s for %s (%s). Please confirm or decline.',
            $this->booking->type->name,
            $this->booking->reference,
            $this->booking->attendeeName() ?? 'a guest',
            $this->booking->starts_at->timezone($this->booking->timezone)->format('D, M j Y H:i'),
            $this->booking->timezone,
        );
    }
}
