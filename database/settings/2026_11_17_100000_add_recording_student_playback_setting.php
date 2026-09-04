<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * SRS §12.20 — "For Version 1, recording visibility may be limited to
 * administrators only or expanded to students based on policy." This
 * is that policy switch: may the student of a recorded lesson watch
 * the finished recording inside SIRI?
 *
 * Ships OFF. Recording acquisition and storage are unaffected by it —
 * a recording is still captured, stored and retained for
 * administrative review exactly as before; this only decides whether
 * RecordingPolicy grants the lesson's own student a watch right.
 * Turning it on is a product activation step, not a deploy step.
 *
 * Deliberately a meeting.* setting rather than a features.* flag: it
 * sits with recording_enabled and recording_retention_days as part of
 * the recording access policy (SRS §12.38 "Recording access policy"),
 * and like them it can only ever NARROW what the platform-wide
 * FeatureSettings::recording_enabled switch allows.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('meeting.recording_student_playback_enabled', false);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('meeting.recording_student_playback_enabled');
    }
};
