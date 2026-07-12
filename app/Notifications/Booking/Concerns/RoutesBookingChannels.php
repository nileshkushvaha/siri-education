<?php

declare(strict_types=1);

namespace App\Notifications\Booking\Concerns;

use App\Booking\Services\NotificationChannelResolver;
use Illuminate\Support\Str;

/**
 * Shared channel routing + text payloads for booking notifications.
 * Channels come from NotificationChannelResolver (BookingSettings
 * toggles + the always-on database channel for real users); each
 * notification only supplies plainText() and toMail().
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

    /**
     * In-app payload rendered by the dashboard Notifications page —
     * `title` and `message` are the keys its Blade reads; no join URL,
     * password, or provider detail beyond what plainText() already
     * deems safe for external channels. Title derives from the class
     * name ("MeetingCreatedNotification" → "Meeting Created"); override
     * in a notification when that wording isn't right.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => Str::headline(Str::beforeLast(class_basename(static::class), 'Notification')),
            'message' => $this->plainText(),
            'booking_reference' => $this->booking->reference ?? null,
        ];
    }

    /** One-line plain-text variant used by WhatsApp/SMS channels. */
    abstract protected function plainText(): string;
}
