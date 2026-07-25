<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // SRS §26.36 "Missing Meeting Link Alert" / §26.43 "Meeting
        // link missing threshold" — how far ahead of an upcoming
        // online lesson the sweep starts alerting when no meeting
        // link exists yet. 60 minutes gives operators a real window
        // to intervene before the lesson starts.
        $this->migrator->add('meeting.missing_meeting_link_threshold_minutes', 60);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('meeting.missing_meeting_link_threshold_minutes');
    }
};
