<?php

declare(strict_types=1);

namespace App\Notifications\Booking;

use App\Booking\DTOs\CancellationRefundDecision;
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
        /** Phase 24C — the frozen outcome; null when the booking had nothing to refund (free demo, unpaid, non-student-initiated omits this entirely for the instructor copy). */
        public readonly ?CancellationRefundDecision $refundDecision = null,
    ) {
        $this->onQueue('notifications');
    }

    public function toMail(object $notifiable): MailMessage
    {
        $timezone = $this->recipientTimezone($notifiable);

        $mail = $this->configureMailMessage(new MailMessage)
            ->subject(sprintf('Booking %s cancelled', $this->booking->reference))
            ->line(sprintf(
                'The %s scheduled for %s (%s) has been cancelled.',
                $this->booking->type->name,
                $this->booking->starts_at->timezone($timezone)->format('D, M j Y \a\t H:i'),
                $timezone,
            ));

        if ($this->booking->cancellation_reason !== null) {
            $mail->line(sprintf('Reason: %s', $this->booking->cancellation_reason));
        }

        if ($refundLine = $this->refundLine()) {
            $mail->line($refundLine);
        }

        return $mail->line(sprintf('Reference: %s', $this->booking->reference));
    }

    protected function plainText(object $notifiable): string
    {
        $timezone = $this->recipientTimezone($notifiable);
        $refundLine = $this->refundLine();

        return sprintf(
            'Booking %s cancelled: %s on %s (%s).%s%s',
            $this->booking->reference,
            $this->booking->type->name,
            $this->booking->starts_at->timezone($timezone)->format('D, M j Y H:i'),
            $timezone,
            $this->booking->cancellation_reason !== null ? ' Reason: '.$this->booking->cancellation_reason : '',
            $refundLine !== null ? ' '.$refundLine : '',
        );
    }

    /** States the frozen outcome only — never recalculated at send time. */
    private function refundLine(): ?string
    {
        if ($this->refundDecision === null) {
            return null;
        }

        return $this->refundDecision->eligible
            ? 'The amount paid has been credited to your wallet.'
            : 'This cancellation was outside the refund window, so no refund was issued.';
    }
}
