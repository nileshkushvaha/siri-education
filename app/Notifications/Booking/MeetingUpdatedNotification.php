<?php

declare(strict_types=1);

namespace App\Notifications\Booking;

use App\Models\Booking;
use App\Models\BookingMeeting;
use App\Notifications\Booking\Concerns\RoutesBookingChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the student and the instructor when an existing meeting's
 * join link is replaced (admin manual update, or a provider retry that
 * produced a new meeting). Same safety rules as MeetingCreatedNotification.
 */
final class MeetingUpdatedNotification extends BookingNotification
{
    use Queueable, RoutesBookingChannels, SerializesModels;

    public function __construct(
        public readonly Booking $booking,
        public readonly BookingMeeting $meeting,
    ) {
        $this->onQueue('notifications');
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = $this->configureMailMessage(new MailMessage)
            ->subject(sprintf('Meeting link updated for booking %s', $this->booking->reference))
            ->line(sprintf(
                'The meeting link for your %s on %s (%s) has changed — please use the new link below.',
                $this->booking->type->name,
                $this->meeting->starts_at->timezone($this->meeting->timezone)->format('D, M j Y \a\t H:i'),
                $this->meeting->timezone,
            ));

        if ($this->meeting->join_url !== null) {
            $mail->action('Join meeting', $this->meeting->join_url);
        }

        if ($this->meeting->password !== null) {
            $mail->line(sprintf('Passcode: %s', $this->meeting->password));
        }

        return $mail->line(sprintf('Reference: %s', $this->booking->reference));
    }

    protected function plainText(): string
    {
        return sprintf(
            'Meeting link updated for booking %s.%s',
            $this->booking->reference,
            $this->meeting->join_url !== null ? ' New link: '.$this->meeting->join_url : '',
        );
    }
}
