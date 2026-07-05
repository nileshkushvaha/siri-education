<?php

declare(strict_types=1);

namespace App\Notifications\Booking;

use App\Models\Booking;
use App\Notifications\Booking\Concerns\RoutesBookingChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\SerializesModels;

final class BookingConfirmedNotification extends BookingNotification
{
    use Queueable, RoutesBookingChannels, SerializesModels;

    public function __construct(
        public readonly Booking $booking,
    ) {
        $this->onQueue('notifications');
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = $this->configureMailMessage(new MailMessage)
            ->subject(sprintf('Booking %s confirmed', $this->booking->reference))
            ->line(sprintf(
                'Your %s on %s (%s) is confirmed.',
                $this->booking->type->name,
                $this->booking->starts_at->timezone($this->booking->timezone)->format('D, M j Y \a\t H:i'),
                $this->booking->timezone,
            ));

        if ($this->booking->meeting_url !== null) {
            $mail->action('Join meeting', $this->booking->meeting_url);
        }

        return $mail->line(sprintf('Reference: %s', $this->booking->reference));
    }

    protected function plainText(): string
    {
        return sprintf(
            'Booking %s confirmed: %s on %s (%s).',
            $this->booking->reference,
            $this->booking->type->name,
            $this->booking->starts_at->timezone($this->booking->timezone)->format('D, M j Y H:i'),
            $this->booking->timezone,
        );
    }
}
