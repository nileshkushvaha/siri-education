<?php

declare(strict_types=1);

namespace App\Homework\Services;

use App\Models\User;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\Channels\WhatsAppChannel;
use App\Settings\HomeworkSettings;

/**
 * Phase 24K — GAP-020: single decision point for homework reminder
 * notification channels, mirroring Booking's NotificationChannelResolver.
 * Admin toggles channels in HomeworkSettings; an empty result (every
 * channel off) is a legitimate "suppressed" outcome, not an error.
 */
final class HomeworkNotificationChannelResolver
{
    public function __construct(private readonly HomeworkSettings $settings) {}

    /** @return list<string> */
    public function channels(object $notifiable): array
    {
        $channels = [];

        if ($notifiable instanceof User) {
            $channels[] = 'database';
        }

        if ($this->settings->homework_reminder_channel_email_enabled) {
            $channels[] = 'mail';
        }

        if ($this->settings->homework_reminder_channel_whatsapp_enabled) {
            $channels[] = WhatsAppChannel::class;
        }

        if ($this->settings->homework_reminder_channel_sms_enabled) {
            $channels[] = SmsChannel::class;
        }

        return $channels;
    }
}
