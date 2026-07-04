<?php

declare(strict_types=1);

namespace App\Notifications\Booking\Concerns;

use App\Booking\Services\NotificationChannelResolver;

/**
 * Shared channel routing + text payloads for booking notifications.
 * Channels come from NotificationChannelResolver (BookingSettings
 * toggles); each notification only supplies plainText() and toMail().
 */
trait RoutesBookingChannels
{
    public function via(object $notifiable): array
    {
        // Queued notifications are unserialized, so constructor injection
        // is unavailable here — container resolution is the escape hatch.
        return app(NotificationChannelResolver::class)->channels($notifiable);
    }

    public function toWhatsApp(object $notifiable): string
    {
        return $this->plainText();
    }

    public function toSms(object $notifiable): string
    {
        return $this->plainText();
    }

    /** One-line plain-text variant used by WhatsApp/SMS channels. */
    abstract protected function plainText(): string;
}
