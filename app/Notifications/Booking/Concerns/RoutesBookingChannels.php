<?php

declare(strict_types=1);

namespace App\Notifications\Booking\Concerns;

use App\Booking\Services\NotificationChannelResolver;
use App\Notifications\Concerns\FormatsRecipientLocalTime;
use Illuminate\Support\Str;

/**
 * Shared channel routing + text payloads for booking notifications.
 * Channels come from NotificationChannelResolver (BookingSettings
 * toggles + the always-on database channel for real users); each
 * notification only supplies plainText() and toMail().
 */
trait RoutesBookingChannels
{
    // TZ-3: recipientTimezone() moved here from this trait into a
    // shared one so the Reviews and Quality notifications can use the
    // identical implementation rather than a second copy. Behaviour is
    // unchanged for every booking notification.
    use FormatsRecipientLocalTime;

    public function via(object $notifiable): array
    {
        // Queued notifications are unserialized, so constructor injection
        // is unavailable here — container resolution is the escape hatch.
        return app(NotificationChannelResolver::class)->channels($notifiable);
    }

    public function toWhatsApp(object $notifiable): string
    {
        return $this->plainText($notifiable);
    }

    public function toSms(object $notifiable): string
    {
        return $this->plainText($notifiable);
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
            'message' => $this->plainText($notifiable),
            'booking_reference' => $this->booking->reference ?? null,
        ];
    }

    /**
     * One-line plain-text variant used by WhatsApp/SMS/database
     * channels. Takes the actual notifiable so
     * each recipient's own timezone can be resolved; never resolve
     * from $this->booking->student or any other shared/cached value.
     */
    abstract protected function plainText(object $notifiable): string;
}
