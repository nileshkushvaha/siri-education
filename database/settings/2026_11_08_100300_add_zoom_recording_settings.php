<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Per-provider recording switches and the Zoom webhook secret.
 *
 * `zoom_recording_enabled` is the Zoom counterpart of the existing
 * `google_meet_recording_enabled`. The two are deliberately separate
 * settings rather than one global flag, because provider selection and
 * recording selection are independent decisions — every combination
 * must be expressible:
 *
 *     Google Meet + recording off     Zoom + recording off
 *     Google Meet + recording on      Zoom + recording on
 *
 * The platform-wide master switches (FeatureSettings::recording_enabled,
 * MeetingSettings::recording_enabled, country rules) still apply on top
 * of these; a provider switch can only ever narrow, never widen.
 *
 * Ships OFF, like its Meet counterpart: Zoom recording needs a licensed
 * account with cloud recording, a webhook subscription, and the account
 * privacy settings described in docs/meetings.md.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('meeting.zoom_recording_enabled', false);
        // Encrypted at rest, exactly like zoom_client_secret. Used to
        // verify webhook signatures and to answer Zoom's endpoint
        // URL-validation challenge.
        $this->migrator->add('meeting.zoom_webhook_secret', null);
        // Accept Zoom recording webhooks. Separate from the credential
        // so an operator can stop ingesting without rotating secrets.
        $this->migrator->add('meeting.zoom_recording_webhooks_enabled', false);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('meeting.zoom_recording_enabled');
        $this->migrator->deleteIfExists('meeting.zoom_webhook_secret');
        $this->migrator->deleteIfExists('meeting.zoom_recording_webhooks_enabled');
    }
};
