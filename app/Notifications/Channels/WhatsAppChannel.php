<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Future gateway integration point (Twilio, Meta Cloud API, …).
 * Until a provider is wired in, messages are logged and skipped so
 * enabling the channel never breaks delivery of other channels.
 */
final class WhatsAppChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toWhatsApp')) {
            return;
        }

        Log::info('WhatsApp gateway not configured — message skipped.', [
            'notification' => $notification::class,
            'message' => $notification->toWhatsApp($notifiable),
        ]);
    }
}
