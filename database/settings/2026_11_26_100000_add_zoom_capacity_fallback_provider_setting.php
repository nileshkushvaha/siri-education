<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * What happens to a Zoom-bound booking when no Zoom host has room for
 * its window. Ships OFF (null): the booking is refused, exactly as
 * before. Set to 'google_meet' to accept it on Google Meet instead —
 * the switch is audited (meeting_host_capacity_fallback), administrators
 * are notified, and the platform Meet host must join that lesson for it
 * to start and record (docs/meetings.md §4a).
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('meeting.zoom_capacity_fallback_provider', null);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('meeting.zoom_capacity_fallback_provider');
    }
};
