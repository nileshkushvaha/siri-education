<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Disabled-safe: the evidence-driven engine ships OFF, so the
        // legacy lessons:auto-complete sweep keeps sole ownership of
        // finalization until admins opt in (providers must be feeding
        // attendance evidence first, or evidence-less lessons would
        // finalize as both-absent instead of auto-completing).
        $this->migrator->add('lessons.automated_finalization_enabled', false);
        // 30 min after ends_at for provider webhooks/sync to land before
        // the attendance record is sealed.
        $this->migrator->add('lessons.attendance_finalize_delay_minutes', 30);
        // 0 = no extra delay beyond the attendance window (backward-compatible).
        $this->migrator->add('lessons.student_no_show_grace_minutes', 0);
        $this->migrator->add('lessons.instructor_no_show_grace_minutes', 0);
        // 24h for participants to report a technical issue before the
        // outcome finalizes as TechnicalIssue for a human decision.
        $this->migrator->add('lessons.technical_issue_window_minutes', 1440);
        $this->migrator->add('lessons.finalize_batch_size', 100);
    }
};
