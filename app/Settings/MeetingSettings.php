<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class MeetingSettings extends Settings
{
    public string $active_provider;

    public ?string $platform_meeting_account;

    public int $meeting_link_visible_before_minutes;

    public int $meeting_link_visible_after_minutes;

    public bool $recording_enabled;

    public int $recording_retention_days;

    public static function group(): string
    {
        return 'meeting';
    }
}
