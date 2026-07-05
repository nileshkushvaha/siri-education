<?php

declare(strict_types=1);

namespace App\Notifications\Booking;

use App\Models\Booking;
use App\Notifications\Booking\Concerns\RoutesBookingChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\SerializesModels;

final class BookingCancelledNotification extends BookingNotification
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
            ->subject(sprintf('Booking %s cancelled', $this->booking->reference))
            ->line(sprintf(
                'The %s scheduled for %s (%s) has been cancelled.',
                $this->booking->type->name,
                $this->booking->starts_at->timezone($this->booking->timezone)->format('D, M j Y \a\t H:i'),
                $this->booking->timezone,
            ));

        if ($this->booking->cancellation_reason !== null) {
            $mail->line(sprintf('Reason: %s', $this->booking->cancellation_reason));
        }

        return $mail->line(sprintf('Reference: %s', $this->booking->reference));
    }

    protected function plainText(): string
    {
        return sprintf(
            'Booking %s cancelled: %s on %s (%s).%s',
            $this->booking->reference,
            $this->booking->type->name,
            $this->booking->starts_at->timezone($this->booking->timezone)->format('D, M j Y H:i'),
            $this->booking->timezone,
            $this->booking->cancellation_reason !== null ? ' Reason: '.$this->booking->cancellation_reason : '',
        );
    }
}
