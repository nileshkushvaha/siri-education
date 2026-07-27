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
        /** False for a recipient (the student) whose lifecycle no longer permits meeting access; the URL/passcode are then omitted from every channel. */
        public readonly bool $includeJoinUrl = true,
    ) {
        $this->onQueue('notifications');
    }

    public function toMail(object $notifiable): MailMessage
    {
        $timezone = $this->recipientTimezone($notifiable);

        $mail = $this->configureMailMessage(new MailMessage)
            ->subject(sprintf('Meeting link updated for booking %s', $this->booking->reference))
            ->line(sprintf(
                'The meeting link for your %s on %s (%s) has changed — please use the new link below.',
                $this->booking->type->name,
                $this->meeting->starts_at->timezone($timezone)->format('D, M j Y \a\t H:i'),
                $timezone,
            ));

        if ($this->includeJoinUrl && $this->meeting->join_url !== null) {
            $mail->action('Join meeting', $this->meeting->join_url);
        } else {
            // Outside the visibility window (or when the
            // student's access is otherwise restricted) the credential is
            // withheld; a safe platform link preserves the schedule
            // information without disclosing the provider URL early.
            $mail->action('View your booking', route('dashboard.my-bookings'));
        }

        if ($this->includeJoinUrl && $this->meeting->password !== null) {
            $mail->line(sprintf('Passcode: %s', $this->meeting->password));
        }

        return $mail->line(sprintf('Reference: %s', $this->booking->reference));
    }

    protected function plainText(object $notifiable): string
    {
        return sprintf(
            'Meeting link updated for booking %s.%s',
            $this->booking->reference,
            $this->includeJoinUrl && $this->meeting->join_url !== null ? ' New link: '.$this->meeting->join_url : '',
        );
    }
}
