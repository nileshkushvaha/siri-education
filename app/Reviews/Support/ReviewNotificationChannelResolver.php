<?php

declare(strict_types=1);

namespace App\Reviews\Support;

use App\Models\User;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\Channels\WhatsAppChannel;
use App\Settings\ReviewSettings;

/**
 * Single decision point for which channels review/quality-alert
 * notifications use — mirrors
 * App\Booking\Services\NotificationChannelResolver exactly. One
 * shared set of toggles governs every notification in this phase
 * (student, instructor, and admin recipients alike); there is no
 * separate "Review" vs "Admin Alert" channel policy, matching the
 * instruction not to invent new preference categories.
 */
final class ReviewNotificationChannelResolver
{
    public function __construct(
        private readonly ReviewSettings $settings,
    ) {}

    /** @return list<string> Laravel notification channel identifiers */
    public function channels(object $notifiable): array
    {
        $channels = [];

        // In-app (database) is always on for real users — the data
        // source for the dashboard Notifications page, deliberately
        // not one of the toggleable external channels below.
        if ($notifiable instanceof User) {
            $channels[] = 'database';
        }

        if ($this->settings->review_channel_email_enabled) {
            $channels[] = 'mail';
        }

        if ($this->settings->review_channel_whatsapp_enabled) {
            $channels[] = WhatsAppChannel::class;
        }

        if ($this->settings->review_channel_sms_enabled) {
            $channels[] = SmsChannel::class;
        }

        return $channels;
    }
}
