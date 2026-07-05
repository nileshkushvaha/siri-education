<?php

declare(strict_types=1);

namespace App\Notifications\Booking;

use App\Models\Booking;
use App\Notifications\Booking\Concerns\RoutesBookingChannels;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\SerializesModels;

final class BookingRescheduledNotification extends BookingNotification
{
    use Queueable, RoutesBookingChannels, SerializesModels;

    public function __construct(
        public readonly Booking $booking,
        public readonly CarbonImmutable $previousStartsAt,
    ) {
        $this->onQueue('notifications');
    }

    public function toMail(object $notifiable): MailMessage
    {
        $timezone = $this->booking->timezone;

        return $this->configureMailMessage(new MailMessage)
            ->subject(sprintf('Booking %s rescheduled', $this->booking->reference))
            ->line(sprintf(
                'Your %s has moved from %s to %s (%s).',
                $this->booking->type->name,
                $this->previousStartsAt->timezone($timezone)->format('D, M j Y \a\t H:i'),
                $this->booking->starts_at->timezone($timezone)->format('D, M j Y \a\t H:i'),
                $timezone,
            ))
            ->line(sprintf('Reference: %s', $this->booking->reference));
    }

    protected function plainText(): string
    {
        $timezone = $this->booking->timezone;

        return sprintf(
            'Booking %s rescheduled: %s moved from %s to %s (%s).',
            $this->booking->reference,
            $this->booking->type->name,
            $this->previousStartsAt->timezone($timezone)->format('D, M j Y H:i'),
            $this->booking->starts_at->timezone($timezone)->format('D, M j Y H:i'),
            $timezone,
        );
    }
}
