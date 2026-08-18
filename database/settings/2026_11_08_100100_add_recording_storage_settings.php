<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Google Drive DESTINATION settings for recording storage, plus the
 * stalled-transfer threshold the reconciliation sweep needs.
 *
 * Credentials are deliberately NOT added here: Drive reuses the
 * service-account JSON and delegated Workspace account already stored
 * (encrypted) for Google Meet — meeting.google_credentials_json and
 * meeting.platform_meeting_account. One Google identity, one place to
 * rotate it.
 *
 * Which storage BACKEND is active is not a setting at all; it is
 * deployment configuration (config/recordings.php), because switching
 * backends is a migration, not a toggle an admin should be able to
 * flip between two uploads.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        // Drive folder that owns the recording hierarchy. Ships empty:
        // recording storage fails closed until an operator sets it.
        $this->migrator->add('meeting.recording_drive_root_folder_id', null);
        // Set when the root folder lives in a Workspace Shared Drive
        // (recommended — ownership then survives any single account).
        $this->migrator->add('meeting.recording_drive_shared_drive_id', null);
        // A Transferring recording older than this was abandoned by a
        // crashed worker and is returned to Pending by recordings:capture.
        // Must exceed the realistic upload time for a full lesson.
        $this->migrator->add('meeting.recording_transfer_stale_minutes', 120);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('meeting.recording_drive_root_folder_id');
        $this->migrator->deleteIfExists('meeting.recording_drive_shared_drive_id');
        $this->migrator->deleteIfExists('meeting.recording_transfer_stale_minutes');
    }
};
