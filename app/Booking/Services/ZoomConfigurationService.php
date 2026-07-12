<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\ZoomMeetingClient;
use App\Booking\DTOs\ZoomDiagnostics;
use App\Booking\Meetings\Concerns\SanitizesProviderMessages;
use App\Settings\MeetingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Determines and persists Zoom's admin-facing readiness status
 * (`not_configured`/`incomplete`/`invalid`/`ready`), mirroring
 * GoogleCalendarConfigurationService's live verification: after the
 * local field/format checks pass, `ready` requires, in order:
 *   1. ZoomMeetingClient::validateCredentials() to actually mint a
 *      Server-to-Server OAuth token, so structurally-plausible-but-wrong
 *      credentials are marked `invalid`, never `ready`
 *   2. a real temporary meeting to be created under the configured host
 *      user and then deleted — a mintable token alone does not prove
 *      the host user exists, is licensed, or that the app's granted
 *      scopes cover meeting creation (exactly the class of
 *      works-on-validation, fails-on-first-booking gap the Google
 *      check closed)
 *
 * The temporary meeting is always deleted (best-effort, in a finally
 * block) regardless of outcome. Those network calls happen only inside
 * the explicit admin "Validate Zoom Configuration" action (tests bind a
 * fake client) — never on ordinary settings page loads. Any live-step
 * failure is captured, sanitized, and exposed via lastDiagnostic()/
 * lastDiagnostics() for the admin notification; never a secret.
 */
final class ZoomConfigurationService
{
    use SanitizesProviderMessages;

    private ?string $lastDiagnostic = null;

    private ?ZoomDiagnostics $lastDiagnostics = null;

    public function __construct(
        private readonly MeetingSettings $settings,
        private readonly ZoomMeetingClient $client,
    ) {}

    public function check(): string
    {
        $this->lastDiagnostic = null;
        $this->lastDiagnostics = null;

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

        return $this->persist($this->verifyLiveMeetingCapability() ? 'ready' : 'invalid');
    }

    /** Sanitized reason the last check() call did not return 'ready' — null when it did, or before any check(). */
    public function lastDiagnostic(): ?string
    {
        return $this->lastDiagnostic;
    }

    /** Structured, non-secret runtime diagnostics from the last check() call. */
    public function lastDiagnostics(): ?ZoomDiagnostics
    {
        return $this->lastDiagnostics;
    }

    private function verifyLiveMeetingCapability(): bool
    {
        $hostUser = $this->settings->zoom_host_user_id ?? (string) $this->settings->zoom_host_email;

        if (! $this->client->validateCredentials()) {
            $this->lastDiagnostic = 'Zoom did not issue an access token for the stored account id / client id / client secret.';
            $this->lastDiagnostics = $this->diagnostics($hostUser, tokenAcquired: false, meetingVerified: false);

            return false;
        }

        $verified = $this->attemptTemporaryMeetingCreation($hostUser);
        $this->lastDiagnostics = $this->diagnostics($hostUser, tokenAcquired: true, meetingVerified: $verified);

        return $verified;
    }

    private function attemptTemporaryMeetingCreation(string $hostUser): bool
    {
        $meetingId = null;

        try {
            $meeting = $this->client->createMeeting($hostUser, $this->temporaryMeetingPayload());
            $meetingId = $meeting['id'] !== '' ? $meeting['id'] : null;

            if (blank($meeting['join_url'])) {
                $this->lastDiagnostic = 'Zoom created the test meeting but returned no join URL.';

                return false;
            }

            return true;
        } catch (Throwable $e) {
            $this->lastDiagnostic = $this->sanitize('Test meeting creation failed: '.$e->getMessage());

            return false;
        } finally {
            if ($meetingId !== null) {
                try {
                    $this->client->deleteMeeting($meetingId);
                } catch (Throwable) {
                    // Best-effort cleanup only — the outcome above is already diagnosed.
                }
            }
        }
    }

    /** @return array<string, mixed> */
    private function temporaryMeetingPayload(): array
    {
        return [
            'topic' => 'Zoom configuration test (safe to ignore)',
            'type' => 2,
            'start_time' => CarbonImmutable::now()->addWeek()->utc()->format('Y-m-d\TH:i:s\Z'),
            'duration' => 15,
            'timezone' => 'UTC',
            'agenda' => 'Automated Zoom configuration test meeting — deleted immediately after the check completes.',
            'settings' => [
                'join_before_host' => false,
                'waiting_room' => true,
            ],
        ];
    }

    private function diagnostics(string $hostUser, bool $tokenAcquired, bool $meetingVerified): ZoomDiagnostics
    {
        return new ZoomDiagnostics(
            accountId: $this->settings->zoom_account_id,
            clientId: $this->settings->zoom_client_id,
            hostUser: $hostUser,
            tokenAcquired: $tokenAcquired,
            meetingCreationVerified: $meetingVerified,
            error: $this->lastDiagnostic,
        );
    }

    private function persist(string $status): string
    {
        $this->settings->zoom_config_status = $status;
        $this->settings->zoom_last_checked_at = Carbon::now()->toIso8601String();
        $this->settings->save();

        return $status;
    }
}
