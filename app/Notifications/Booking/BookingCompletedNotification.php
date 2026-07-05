<?php

declare(strict_types=1);

namespace App\Notifications\Booking;

use App\Booking\Enums\BookingStatus;
use App\Models\Booking;
use App\Notifications\Booking\Concerns\RoutesBookingChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\SerializesModels;

/** Covers both terminal outcomes — Completed and NoShow. */
final class BookingCompletedNotification extends BookingNotification
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
            ->subject(sprintf(
                'Booking %s %s',
                $this->booking->reference,
                $this->isNoShow() ? 'marked as no-show' : 'completed',
            ));

        if ($this->isNoShow()) {
            return $mail
                ->line(sprintf(
                    'The %s scheduled for %s (%s) was marked as a no-show.',
                    $this->booking->type->name,
                    $this->booking->starts_at->timezone($this->booking->timezone)->format('D, M j Y \a\t H:i'),
                    $this->booking->timezone,
                ))
                ->line('If this looks wrong, please get in touch and we will sort it out.')
                ->line(sprintf('Reference: %s', $this->booking->reference));
        }

        return $mail
            ->line(sprintf('Thanks for attending your %s!', $this->booking->type->name))
            ->line('We hope it was helpful — feel free to book a follow-up session any time.')
            ->line(sprintf('Reference: %s', $this->booking->reference));
    }

    protected function plainText(): string
    {
        return sprintf(
            'Booking %s (%s) %s.',
            $this->booking->reference,
            $this->booking->type->name,
            $this->isNoShow() ? 'was marked as a no-show' : 'is complete — thanks for attending',
        );
    }

    private function isNoShow(): bool
    {
        return $this->booking->status === BookingStatus::NoShow;
    }
}
