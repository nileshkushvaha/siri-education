<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Master switch for Google Meet recording ACQUISITION.
 *
 * Ships OFF, and that is deliberate rather than cautious boilerplate:
 * the Meet lookup and the Drive artifact read both require OAuth
 * scopes (meetings.space.readonly, drive.meet.readonly) that must be
 * added to the Workspace domain-wide delegation grant by an
 * administrator first. Turning this on before that grant exists would
 * make every lesson's recording fail with a permission error.
 *
 * With it off, GoogleCalendarMeetProvider::supportsRecording() returns
 * false, so RecordingEligibilityResolver declines cleanly and no
 * recording rows are created at all — meeting creation and every other
 * Google integration are entirely unaffected.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('meeting.google_meet_recording_enabled', false);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('meeting.google_meet_recording_enabled');
    }
};
