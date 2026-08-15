<?php

declare(strict_types=1);

namespace App\Notifications\Package\Concerns;

use App\Booking\Services\NotificationChannelResolver;
use App\Notifications\Concerns\FormatsRecipientLocalTime;
use Illuminate\Support\Str;

/**
 * Channel routing for package notifications. Deliberately resolves
 * through the SAME NotificationChannelResolver the booking family uses
 * — it is the app's single decision point for participant channels
 * (the admin-toggleable email/WhatsApp/SMS switches plus the always-on
 * database channel for real users). A package purchase is a
 * participant-facing lesson event, so inventing a second resolver and
 * a second set of toggles would just create two answers to one
 * question.
 *
 * Only the in-app payload differs from the booking trait: it carries
 * the package purchase reference rather than a booking reference.
 */
trait RoutesPackageChannels
{
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
     * `title` and `message` are the keys its Blade reads.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => Str::headline(Str::beforeLast(class_basename(static::class), 'Notification')),
            'message' => $this->plainText($notifiable),
            'package_reference' => $this->purchase->reference ?? null,
        ];
    }

    /**
     * One-line plain-text variant for the WhatsApp/SMS/database
     * channels. Takes the actual notifiable so each recipient's own
     * timezone is resolved — never a shared or cached value.
     */
    abstract protected function plainText(object $notifiable): string;
}
