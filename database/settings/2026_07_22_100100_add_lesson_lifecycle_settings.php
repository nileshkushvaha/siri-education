<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('lessons.auto_complete_enabled', true);
        // 24h: the window in which participants can still report a no-show
        // before the sweep assumes the lesson was delivered.
        $this->migrator->add('lessons.auto_complete_grace_minutes', 1440);
        // A no-show can't be recorded until the lesson is meaningfully late.
        $this->migrator->add('lessons.no_show_grace_minutes', 15);
        // Both off: completion (manual + auto) works without explicit
        // attendance confirmation until admins opt in to stricter rules.
        $this->migrator->add('lessons.require_instructor_completion', false);
        $this->migrator->add('lessons.require_student_attendance', false);
    }
};
