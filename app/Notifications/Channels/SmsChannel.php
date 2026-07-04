<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Future gateway integration point (Twilio, Vonage, …).
 * Until a provider is wired in, messages are logged and skipped so
 * enabling the channel never breaks delivery of other channels.
 */
final class SmsChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toSms')) {
            return;
        }

        Log::info('SMS gateway not configured — message skipped.', [
            'notification' => $notification::class,
            'message' => $notification->toSms($notifiable),
        ]);
    }
}
