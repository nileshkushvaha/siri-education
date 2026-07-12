<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Disabled-safe default: 0 means any recorded join qualifies as
        // attendance, so enabling the attendance layer changes nothing
        // until admins opt in to a stricter minimum.
        $this->migrator->add('lessons.min_attendance_seconds', 0);
    }
};
