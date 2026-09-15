<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Instructor as Google Meet co-host. Ships OFF. When on, every lesson
 * space created through the Meet API also adds the instructor's Google
 * account as a COHOST member (Meet REST API v2 spaces.members), so the
 * instructor enters without waiting in the lobby and can admit and
 * manage participants. Non-fatal: a failed co-host add never costs a
 * lesson its meeting; it is recorded on the meeting and surfaced to
 * administrators. See docs/meetings.md §3 "Teacher co-host".
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('meeting.google_meet_cohost_enabled', true);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('meeting.google_meet_cohost_enabled');
    }
};
