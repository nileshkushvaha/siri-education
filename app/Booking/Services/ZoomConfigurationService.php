<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\ZoomMeetingClient;
use App\Settings\MeetingSettings;
use Illuminate\Support\Carbon;

/**
 * Determines and persists Zoom's admin-facing readiness status
 * (`not_configured`/`incomplete`/`invalid`/`ready`), mirroring
 * GoogleCalendarConfigurationService — with one deliberate difference:
 * after the local field/format checks pass, `ready` additionally
 * requires ZoomMeetingClient::validateCredentials() to actually mint a
 * token, so structurally-plausible-but-wrong credentials are marked
 * `invalid`, never `ready`. That network call happens only inside the
 * explicit admin "Validate Zoom Configuration" action (tests bind a
 * fake client) — never on ordinary settings page loads.
 */
final class ZoomConfigurationService
{
    public function __construct(
        private readonly MeetingSettings $settings,
        private readonly ZoomMeetingClient $client,
    ) {}

    public function check(): string
    {
        if (! $this->settings->zoom_enabled) {
            return $this->persist('not_configured');
        }

        if (blank($this->settings->zoom_account_id)
            || blank($this->settings->zoom_client_id)
            || blank($this->settings->zoom_client_secret)) {
            return $this->persist('incomplete');
        }

        if ($this->settings->decryptedZoomClientSecret() === null) {
            return $this->persist('invalid');
        }

        if (blank($this->settings->zoom_host_user_id) && blank($this->settings->zoom_host_email)) {
            return $this->persist('incomplete');
        }

        return $this->persist($this->client->validateCredentials() ? 'ready' : 'invalid');
    }

    private function persist(string $status): string
    {
        $this->settings->zoom_config_status = $status;
        $this->settings->zoom_last_checked_at = Carbon::now()->toIso8601String();
        $this->settings->save();

        return $status;
    }
}
