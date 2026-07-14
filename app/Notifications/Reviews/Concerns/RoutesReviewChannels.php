<?php

declare(strict_types=1);

namespace App\Notifications\Reviews\Concerns;

use App\Reviews\Support\ReviewNotificationChannelResolver;
use Illuminate\Support\Str;

/**
 * Shared channel routing + text payloads for every review/quality-
 * alert notification (student, instructor, and admin recipients
 * alike) — mirrors App\Notifications\Booking\Concerns\RoutesBookingChannels.
 */
trait RoutesReviewChannels
{
    public function via(object $notifiable): array
    {
        // Queued notifications are unserialized, so constructor
        // injection is unavailable here — container resolution is the
        // escape hatch (same reasoning as RoutesBookingChannels).
        return app(ReviewNotificationChannelResolver::class)->channels($notifiable);
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
     * In-app payload rendered by the dashboard Notifications page.
     * `databaseContext()` lets a subclass add safe reference fields
     * (review id, action route) without overriding this method.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => Str::headline(Str::beforeLast(class_basename(static::class), 'Notification')),
            'message' => $this->plainText(),
            ...$this->databaseContext(),
        ];
    }

    /** @return array<string, mixed> */
    protected function databaseContext(): array
    {
        return [];
    }

    /** One-line plain-text variant used by WhatsApp/SMS channels. */
    abstract protected function plainText(): string;
}
