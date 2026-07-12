<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Models\User;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\Channels\WhatsAppChannel;
use App\Settings\BookingSettings;

/**
 * Single decision point for which channels booking notifications use.
 * Admin toggles channels in BookingSettings; adding a gateway means
 * implementing the channel class — notifications never change.
 */
final class NotificationChannelResolver
{
    public function __construct(
        private readonly BookingSettings $settings,
    ) {}

    /** @return list<string> Laravel notification channel identifiers */
    public function channels(object $notifiable): array
    {
        $channels = [];

        // In-app (database) notifications are the data source for the
        // frontend dashboard's Notifications page — always on for real
        // users, deliberately not one of the admin-toggleable external
        // delivery channels below. Guests (AnonymousNotifiable routed to
        // an email address) have no notifications table row to receive
        // them.
        if ($notifiable instanceof User) {
            $channels[] = 'database';
        }

        if ($this->settings->channel_email_enabled) {
            $channels[] = 'mail';
        }

        if ($this->settings->channel_whatsapp_enabled) {
            $channels[] = WhatsAppChannel::class;
        }

        if ($this->settings->channel_sms_enabled) {
            $channels[] = SmsChannel::class;
        }

        return $channels;
    }
}
