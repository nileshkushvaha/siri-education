<?php

declare(strict_types=1);

namespace App\Notifications\Booking;

use App\Models\Booking;
use App\Notifications\Booking\Concerns\RoutesBookingChannels;
use App\Notifications\Templates\NotificationTemplateChannel;
use App\Notifications\Templates\NotificationTemplateKey;
use App\Notifications\Templates\NotificationTemplateRenderer;
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
        $timezone = $this->recipientTimezone($notifiable);

        $rendered = app(NotificationTemplateRenderer::class)->render(
            NotificationTemplateKey::BookingConfirmed,
            NotificationTemplateChannel::Mail,
            [
                'booking_type' => $this->booking->type->name,
                'lesson_datetime' => $this->booking->starts_at->timezone($timezone)->format('D, M j Y \a\t H:i'),
                'timezone' => $timezone,
                'booking_reference' => $this->booking->reference,
            ],
        );

        $mail = $this->configureMailMessage(new MailMessage)->subject($rendered->subject);

        foreach ($rendered->lines as $line) {
            $mail->line($line);
        }

        if ($this->booking->meeting_url !== null) {
            $mail->action('Join meeting', $this->booking->meeting_url);
        }

        return $mail;
    }

    protected function plainText(object $notifiable): string
    {
        $timezone = $this->recipientTimezone($notifiable);

        return sprintf(
            'Booking %s confirmed: %s on %s (%s).',
            $this->booking->reference,
            $this->booking->type->name,
            $this->booking->starts_at->timezone($timezone)->format('D, M j Y H:i'),
            $timezone,
        );
    }
}
