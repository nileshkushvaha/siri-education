<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Settings\MeetingSettings;
use Illuminate\Support\Carbon;

/**
 * Determines and persists Google Meet's admin-facing readiness status
 * (`not_configured`/`incomplete`/`invalid`/`ready`). Never calls the
 * Google API — pure settings/format inspection, mirroring
 * PaymentGatewayConfigurationService's "Validate Configuration" pattern
 * exactly, so this is safe to run in automated tests without stubbing
 * HTTP.
 */
final class GoogleCalendarConfigurationService
{
    public function __construct(
        private readonly MeetingSettings $settings,
    ) {}

    public function check(): string
    {
        if (! $this->settings->google_meet_enabled) {
            return $this->persist('not_configured');
        }

        if (blank($this->settings->google_calendar_id)) {
            return $this->persist('incomplete');
        }

        $credentials = $this->settings->decryptedGoogleCredentials();

        if ($credentials === null) {
            return $this->persist('incomplete');
        }

        $decoded = json_decode($credentials, true);

        if (! is_array($decoded) || blank($decoded['client_email'] ?? null) || blank($decoded['private_key'] ?? null)) {
            return $this->persist('invalid');
        }

        return $this->persist('ready');
    }

    private function persist(string $status): string
    {
        $this->settings->google_config_status = $status;
        $this->settings->google_credentials_configured = $status === 'ready';
        $this->settings->google_last_checked_at = Carbon::now()->toIso8601String();
        $this->settings->save();

        return $status;
    }
}
