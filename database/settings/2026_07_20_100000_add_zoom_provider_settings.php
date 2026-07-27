<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Zoom Server-to-Server OAuth. Disabled by default —
        // ZoomMeetingProvider::isConfigured() fails closed until an admin
        // enables it AND every credential field validates.
        $this->migrator->add('meeting.zoom_enabled', false);
        $this->migrator->add('meeting.zoom_account_id', null);
        $this->migrator->add('meeting.zoom_client_id', null);
        $this->migrator->add('meeting.zoom_client_secret', null);
        $this->migrator->add('meeting.zoom_host_user_id', null);
        $this->migrator->add('meeting.zoom_host_email', null);
        $this->migrator->add('meeting.zoom_default_timezone', null);
        $this->migrator->add('meeting.zoom_config_status', 'not_configured');
        $this->migrator->add('meeting.zoom_last_checked_at', null);
    }
};
