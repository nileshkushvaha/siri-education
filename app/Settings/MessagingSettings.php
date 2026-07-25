<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * SRS §17.29 "completed lessons ... communication remains within
 * allowed window" and §17.45 "Message eligibility rules"/"Message
 * attachment rules" are administrator-configurable.
 */
class MessagingSettings extends Settings
{
    /** Days after a completed lesson's end that messaging eligibility persists. */
    public int $post_lesson_window_days;

    public bool $attachments_enabled;

    public static function group(): string
    {
        return 'messaging';
    }
}
