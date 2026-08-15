<?php

declare(strict_types=1);

namespace App\Notifications\Booking;

use App\Models\Booking;
use App\Notifications\Booking\Concerns\RoutesBookingChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\SerializesModels;

/**
 * The instructor's counterpart to BookingPaymentSucceededNotification.
 *
 * Its purpose is operational — "this lesson is paid for and is now
 * firmly in your schedule" — not commercial. It deliberately carries
 * NO amount, NO currency, NO payment/provider reference, and NO
 * receipt, for two separate reasons:
 *
 *  1. The receipt belongs to the payer alone (SRS §14.21 lists the
 *     student as the document's subject).
 *  2. What the student paid is not what the instructor earns. Those
 *     are different domain concepts and instructor compensation is
 *     owned entirely by the earnings/compensation architecture.
 *     Printing the student's price here would read as earnings and be
 *     wrong.
 *
 * Constructed with only the Booking — it has no BookingPayment
 * parameter at all, so there is no route by which payment detail could
 * later leak into the instructor's copy by accident.
 */
final class BookingPaidLessonConfirmedNotification extends BookingNotification
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
            ->subject(sprintf('Lesson confirmed — booking %s', $this->booking->reference))
            ->greeting('A paid lesson is confirmed')
            ->line(sprintf(
                '%s has completed payment for the %s on %s (%s). It is now confirmed in your teaching schedule.',
                $this->booking->student?->name ?? 'A student',
                $this->booking->type->name,
                $this->booking->starts_at->timezone($timezone)->format('D, M j Y \a\t H:i'),
                $timezone,
            ))
            ->line(sprintf('Booking reference: %s', $this->booking->reference));
    }

    protected function plainText(object $notifiable): string
    {
        $timezone = $this->recipientTimezone($notifiable);

        return sprintf(
            'Lesson confirmed: %s with %s on %s (%s). Reference %s.',
            $this->booking->type->name,
            $this->booking->student?->name ?? 'a student',
            $this->booking->starts_at->timezone($timezone)->format('D, M j Y H:i'),
            $timezone,
            $this->booking->reference,
        );
    }
}
