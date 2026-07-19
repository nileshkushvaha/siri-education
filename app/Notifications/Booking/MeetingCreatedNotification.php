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
 * Sent to the student and the instructor when a meeting becomes
 * available. Content is identical and safe for both: the join URL only
 * — never the host/start URL, a password beyond the join passcode, or
 * any provider metadata.
 */
final class MeetingCreatedNotification extends BookingNotification
{
    use Queueable, RoutesBookingChannels, SerializesModels;

    public function __construct(
        public readonly Booking $booking,
        public readonly BookingMeeting $meeting,
        /** Phase 24H.2A — false for a recipient (the student) whose lifecycle no longer permits meeting access; the URL/passcode are then omitted from every channel. */
        public readonly bool $includeJoinUrl = true,
    ) {
        $this->onQueue('notifications');
    }

    public function toMail(object $notifiable): MailMessage
    {
        $timezone = $this->recipientTimezone($notifiable);

        $mail = $this->configureMailMessage(new MailMessage)
            ->subject(sprintf('Meeting link ready for booking %s', $this->booking->reference))
            ->line(sprintf(
                'The meeting for your %s on %s (%s) is ready.',
                $this->booking->type->name,
                $this->meeting->starts_at->timezone($timezone)->format('D, M j Y \a\t H:i'),
                $timezone,
            ));

        if ($this->includeJoinUrl && $this->meeting->join_url !== null) {
            $mail->action('Join meeting', $this->meeting->join_url);
        } else {
            // Phase 24H.2B — outside the visibility window (or when the
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
        $timezone = $this->recipientTimezone($notifiable);

        return sprintf(
            'Meeting ready for booking %s on %s (%s).%s',
            $this->booking->reference,
            $this->meeting->starts_at->timezone($timezone)->format('D, M j Y H:i'),
            $timezone,
            $this->includeJoinUrl && $this->meeting->join_url !== null ? ' Join: '.$this->meeting->join_url : '',
        );
    }
}
