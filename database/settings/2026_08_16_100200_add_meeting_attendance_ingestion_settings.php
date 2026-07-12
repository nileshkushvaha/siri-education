<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Disabled-safe: both ingestion paths ship OFF. Enabling them
        // feeds attendance evidence only — automated outcomes stay
        // separately gated behind lessons.automated_finalization_enabled.
        $this->migrator->add('meeting.attendance_webhooks_enabled', false);
        $this->migrator->add('meeting.attendance_sync_enabled', false);
        // Wait after the booking ends before the first attendance pull.
        $this->migrator->add('meeting.attendance_sync_delay_minutes', 15);
        // Transient failures keep retrying until this long after the
        // booking end, then become permanent.
        $this->migrator->add('meeting.attendance_sync_retry_minutes', 720);
        // Meetings older than this are never sync candidates.
        $this->migrator->add('meeting.attendance_sync_max_age_hours', 72);
        $this->migrator->add('meeting.attendance_sync_batch_size', 50);
        $this->migrator->add('meeting.attendance_sync_max_attempts', 5);
    }
};
