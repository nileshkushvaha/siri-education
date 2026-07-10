<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Renamed to match this phase's explicit business decision —
        // 'manual' is now a real, working provider, not an off switch,
        // so 'active_provider' (implying "the one that's on") no
        // longer fits; 'default_provider' matches PaymentGatewaySettings'
        // naming for the same concept.
        $this->migrator->rename('meeting.active_provider', 'meeting.default_provider');
        $this->migrator->rename('meeting.create_for_demo_bookings', 'meeting.create_after_demo_booking_confirmation');
        $this->migrator->rename('meeting.create_for_paid_bookings', 'meeting.create_after_paid_booking_confirmation');

        // Platform-wide off switch — was implicit in 'active_provider = manual'
        // before; now explicit so 'manual' can be a real provider.
        $this->migrator->add('meeting.meetings_enabled', false);
        $this->migrator->add('meeting.manual_provider_enabled', true);

        $this->migrator->add('meeting.google_meet_enabled', false);
        $this->migrator->add('meeting.google_calendar_id', null);
        $this->migrator->add('meeting.google_auth_type', 'service_account');
        $this->migrator->add('meeting.google_credentials_json', null);
        $this->migrator->add('meeting.google_oauth_refresh_token', null);
        $this->migrator->add('meeting.google_credentials_configured', false);
        $this->migrator->add('meeting.google_config_status', 'not_configured');
        $this->migrator->add('meeting.google_last_checked_at', null);

        $this->migrator->add('meeting.student_join_url_visible', true);
        $this->migrator->add('meeting.instructor_join_url_visible', true);
    }
};
