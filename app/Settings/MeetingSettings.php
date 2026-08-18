<?php

declare(strict_types=1);

namespace App\Settings;

use Illuminate\Support\Facades\Crypt;
use Spatie\LaravelSettings\Settings;
use Throwable;

class MeetingSettings extends Settings
{
    /** Platform-wide kill switch — false blocks every provider, including manual. */
    public bool $meetings_enabled;

    /** 'manual' | 'google_meet' (Zoom is registered but never selectable — see ZoomMeetingProvider). */
    public string $default_provider;

    public ?string $platform_meeting_account;

    public int $meeting_link_visible_before_minutes;

    public int $meeting_link_visible_after_minutes;

    /** SRS §26.36/§26.43 — how far ahead of an upcoming online lesson the missing-meeting-link sweep starts alerting. */
    public int $missing_meeting_link_threshold_minutes;

    public bool $recording_enabled;

    public int $recording_retention_days;

    /** Auto-create a meeting for demo/free (payment_status = not_required) confirmed bookings. */
    public bool $create_after_demo_booking_confirmation;

    /** Auto-create a meeting for paid (payment_status = paid) confirmed bookings. */
    public bool $create_after_paid_booking_confirmation;

    public bool $manual_provider_enabled;

    public bool $google_meet_enabled;

    public ?string $google_calendar_id;

    /** 'service_account' (implemented) | 'oauth_user' (reserved — no connection screen built yet). */
    public string $google_auth_type;

    /** Encrypted service-account JSON — never rendered back to the admin UI after save. */
    public ?string $google_credentials_json;

    /** Reserved for a future OAuth-user flow; encrypted when set. Not currently used by GoogleCalendarMeetProvider. */
    public ?string $google_oauth_refresh_token;

    public bool $google_credentials_configured;

    /** 'not_configured' | 'incomplete' | 'ready' | 'invalid' — set only by the admin "Test Google Configuration" action, never by hand. */
    public string $google_config_status;

    public ?string $google_last_checked_at;

    /** Set only when the admin actually pastes a new credential JSON — never on an ordinary settings save. */
    public ?string $google_credentials_updated_at;

    public bool $student_join_url_visible;

    public bool $instructor_join_url_visible;

    public bool $zoom_enabled;

    public ?string $zoom_account_id;

    public ?string $zoom_client_id;

    /** Encrypted Server-to-Server OAuth client secret — never rendered back to the admin UI after save. */
    public ?string $zoom_client_secret;

    /** Zoom user the platform schedules meetings under — id takes precedence over email when both are set. */
    public ?string $zoom_host_user_id;

    public ?string $zoom_host_email;

    /** Fallback meeting timezone when a booking has none — platform default applies after this. */
    public ?string $zoom_default_timezone;

    /** 'not_configured' | 'incomplete' | 'ready' | 'invalid' — set only by the admin "Validate Zoom Configuration" action, never by hand. */
    public string $zoom_config_status;

    public ?string $zoom_last_checked_at;

    /** Accept meeting-attendance webhooks. Ships OFF — evidence only, never outcomes. */
    public bool $attendance_webhooks_enabled;

    /** Run meetings:sync-attendance pulls. Ships OFF. */
    public bool $attendance_sync_enabled;

    /** Minutes after the booking end before the first attendance pull. */
    public int $attendance_sync_delay_minutes;

    /** Transient sync failures retry until this many minutes after the booking end, then become permanent. */
    public int $attendance_sync_retry_minutes;

    /** Meetings whose booking ended longer ago than this are never sync candidates. */
    public int $attendance_sync_max_age_hours;

    /** Meetings per processing chunk in meetings:sync-attendance. */
    public int $attendance_sync_batch_size;

    /** Bounded retries per meeting before a sync failure is marked permanent. */
    public int $attendance_sync_max_attempts;

    /** Minutes after a booking ends before the first recording-capture attempt. */
    public int $recording_capture_delay_minutes;

    /** Transient capture failures keep retrying until this many minutes after the booking end. */
    public int $recording_capture_retry_minutes;

    /** Meetings whose booking ended longer ago than this are never capture candidates. */
    public int $recording_capture_max_age_hours;

    /** Meetings per processing chunk in recordings:capture. */
    public int $recording_capture_batch_size;

    /** Bounded retries per meeting before a capture failure is marked permanent. */
    public int $recording_capture_max_attempts;

    /** Recordings per processing chunk in recordings:expire. */
    public int $recording_expiry_batch_size;

    /**
     * Enable Google Meet recording ACQUISITION (Meet REST API lookup +
     * Drive artifact read). Ships OFF: it needs the
     * meetings.space.readonly and drive.meet.readonly scopes present in
     * the Workspace domain-wide delegation grant. Separate from
     * recording_enabled, which governs the feature as a whole.
     */
    public bool $google_meet_recording_enabled;

    /**
     * Google Drive folder that owns the recording hierarchy
     * (SIRI Education Recordings/YYYY/MM). Not a credential — an id.
     * Empty means Drive recording storage is not configured, and
     * ingestion fails closed rather than inventing a destination.
     */
    public ?string $recording_drive_root_folder_id;

    /** Set when the root folder lives in a Workspace Shared Drive rather than the platform account's My Drive. */
    public ?string $recording_drive_shared_drive_id;

    /** A Transferring recording older than this was abandoned by a crashed worker; recordings:capture returns it to Pending. */
    public int $recording_transfer_stale_minutes;

    public static function group(): string
    {
        return 'meeting';
    }

    /**
     * The single decrypt point for the Google service-account JSON —
     * blank or undecryptable values fail closed to null, so every
     * consumer (provider isConfigured(), config service, SDK client)
     * shares one rule instead of five copies of the same try/catch.
     */
    public function decryptedGoogleCredentials(): ?string
    {
        return $this->decrypt($this->google_credentials_json);
    }

    /** Same fail-closed rule for the Zoom Server-to-Server client secret. */
    public function decryptedZoomClientSecret(): ?string
    {
        return $this->decrypt($this->zoom_client_secret);
    }

    private function decrypt(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            return null;
        }
    }
}
