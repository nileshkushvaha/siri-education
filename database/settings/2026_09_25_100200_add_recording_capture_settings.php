<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Recording capture retry/reconciliation windows, mirroring
 * the existing attendance_sync_* settings exactly. Recording itself
 * stays gated by the existing
 * meeting.recording_enabled + meeting.recording_retention_days
 * (already present as foundation-only settings) — this
 * migration only adds the NEW timing/retry knobs the capture pipeline
 * needs.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        // Wait this long after the booking ends before the first capture attempt.
        $this->migrator->add('meeting.recording_capture_delay_minutes', 15);
        // Transient capture failures keep retrying until this long after the booking end.
        $this->migrator->add('meeting.recording_capture_retry_minutes', 1440);
        // Meetings older than this are never capture candidates.
        $this->migrator->add('meeting.recording_capture_max_age_hours', 168);
        $this->migrator->add('meeting.recording_capture_batch_size', 50);
        $this->migrator->add('meeting.recording_capture_max_attempts', 5);
        // Retention-expiry sweep batch size.
        $this->migrator->add('meeting.recording_expiry_batch_size', 100);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('meeting.recording_capture_delay_minutes');
        $this->migrator->deleteIfExists('meeting.recording_capture_retry_minutes');
        $this->migrator->deleteIfExists('meeting.recording_capture_max_age_hours');
        $this->migrator->deleteIfExists('meeting.recording_capture_batch_size');
        $this->migrator->deleteIfExists('meeting.recording_capture_max_attempts');
        $this->migrator->deleteIfExists('meeting.recording_expiry_batch_size');
    }
};
