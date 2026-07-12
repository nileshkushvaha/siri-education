<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\GoogleCalendarClient;
use App\Booking\DTOs\GoogleCalendarDiagnostics;
use App\Booking\Meetings\Concerns\SanitizesProviderMessages;
use App\Settings\MeetingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Determines and persists Google Meet's admin-facing readiness status
 * (`not_configured`/`incomplete`/`invalid`/`ready`) for the "Test
 * Google Configuration" admin action.
 *
 * Unlike a plain format check, `ready` additionally requires (mirroring
 * ZoomConfigurationService::check()'s live validateCredentials() step,
 * scaled up for Google's richer failure modes) an ordered live sequence
 * that never skips ahead on failure:
 *   1. decode the credential (format only)
 *   2. validate credential metadata (client_id, client_email, private_key)
 *   3. the delegated subject (platform_meeting_account) must be set
 *   4. request only Calendar::CALENDAR (GoogleCalendarSdkClient's own
 *      concern — this class never touches scopes directly)
 *   5. fetch an access token with the service-account assertion —
 *      isolates OAuth/domain-wide-delegation failures (e.g. `401
 *      unauthorized_client`) from Calendar API failures
 *   6. access the primary calendar as the delegated user
 *   7. verify `hangoutsMeet` is in the calendar's allowed conference
 *      solution types
 *   8. create a real temporary event with its own Meet createRequest,
 *      confirm it, then delete it (always, in a finally block)
 *
 * The temporary event is always deleted regardless of outcome — this
 * check must never leave stray events on the platform calendar. Any
 * live-step failure is captured, sanitized, and exposed via both
 * lastDiagnostic() (a human-readable reason) and lastDiagnostics() (a
 * structured, non-secret GoogleCalendarDiagnostics DTO) for the admin
 * notification; neither ever carries a private key, token, or raw
 * credential JSON.
 */
final class GoogleCalendarConfigurationService
{
    use SanitizesProviderMessages;

    private const GOOGLE_MEET_CONFERENCE_TYPE = 'hangoutsMeet';

    private const RECONFIRM_ATTEMPTS = 3;

    private const RECONFIRM_DELAY_MICROSECONDS = 500_000;

    private ?string $lastDiagnostic = null;

    private ?GoogleCalendarDiagnostics $lastDiagnostics = null;

    public function __construct(
        private readonly MeetingSettings $settings,
        private readonly GoogleCalendarClient $client,
    ) {}

    public function check(): string
    {
        $this->lastDiagnostic = null;
        $this->lastDiagnostics = null;

        if (! $this->settings->google_meet_enabled) {
            return $this->persist('not_configured');
        }

        if (blank($this->settings->google_calendar_id) || blank($this->settings->platform_meeting_account)) {
            return $this->persist('incomplete');
        }

        $credentials = $this->settings->decryptedGoogleCredentials();

        if ($credentials === null) {
            return $this->persist('incomplete');
        }

        $decoded = json_decode($credentials, true);

        if (! is_array($decoded)
            || blank($decoded['client_id'] ?? null)
            || blank($decoded['client_email'] ?? null)
            || blank($decoded['private_key'] ?? null)
        ) {
            return $this->persist('invalid');
        }

        return $this->persist($this->verifyLiveMeetCapability($credentials, $decoded) ? 'ready' : 'invalid');
    }

    /** Sanitized reason the last check() call did not return 'ready' — null when it did, or before any check(). */
    public function lastDiagnostic(): ?string
    {
        return $this->lastDiagnostic;
    }

    /** Structured, non-secret runtime diagnostics from the last check() call. */
    public function lastDiagnostics(): ?GoogleCalendarDiagnostics
    {
        return $this->lastDiagnostics;
    }

    /** @param  array<string, mixed>  $decodedCredentials */
    private function verifyLiveMeetCapability(string $credentials, array $decodedCredentials): bool
    {
        $calendarId = (string) $this->settings->google_calendar_id;
        $subject = (string) $this->settings->platform_meeting_account;
        $clientId = (string) $decodedCredentials['client_id'];
        $clientEmail = (string) $decodedCredentials['client_email'];
        $scopes = $this->client->requestedScopes();

        // Step 5: token acquisition, isolated from any Calendar API call —
        // a `401 unauthorized_client` (scope not covered by domain-wide
        // delegation, revoked key) is diagnosed here, distinctly from a
        // Meet-capability or event-creation failure below.
        try {
            $this->client->verifyTokenAcquisition($credentials, $subject);
        } catch (Throwable $e) {
            $this->lastDiagnostic = $this->sanitize($e->getMessage());
            $this->lastDiagnostics = new GoogleCalendarDiagnostics(
                clientId: $clientId,
                clientEmail: $clientEmail,
                delegatedSubject: $subject,
                requestedScopes: $scopes,
                calendarId: $calendarId,
                tokenAcquired: false,
                allowedConferenceTypes: [],
                error: $this->lastDiagnostic,
            );

            return false;
        }

        // Steps 6–7: primary calendar access + hangoutsMeet capability.
        try {
            $allowed = $this->client->allowedConferenceTypes($credentials, $calendarId, $subject);
        } catch (Throwable $e) {
            $this->lastDiagnostic = $this->sanitize('Calendar access failed: '.$e->getMessage());
            $this->lastDiagnostics = new GoogleCalendarDiagnostics(
                clientId: $clientId,
                clientEmail: $clientEmail,
                delegatedSubject: $subject,
                requestedScopes: $scopes,
                calendarId: $calendarId,
                tokenAcquired: true,
                allowedConferenceTypes: [],
                error: $this->lastDiagnostic,
            );

            return false;
        }

        if (! in_array(self::GOOGLE_MEET_CONFERENCE_TYPE, $allowed, true)) {
            $this->lastDiagnostic = sprintf(
                'Google Meet is not supported by the configured calendar for %s. Allowed conference types: [%s]',
                $subject,
                implode(', ', $allowed) ?: 'none',
            );
            $this->lastDiagnostics = new GoogleCalendarDiagnostics(
                clientId: $clientId,
                clientEmail: $clientEmail,
                delegatedSubject: $subject,
                requestedScopes: $scopes,
                calendarId: $calendarId,
                tokenAcquired: true,
                allowedConferenceTypes: $allowed,
                error: $this->lastDiagnostic,
            );

            return false;
        }

        // Step 8: real temporary Meet event, confirmed then deleted.
        $success = $this->attemptTemporaryMeetCreation($credentials, $calendarId, $subject);

        $this->lastDiagnostics = new GoogleCalendarDiagnostics(
            clientId: $clientId,
            clientEmail: $clientEmail,
            delegatedSubject: $subject,
            requestedScopes: $scopes,
            calendarId: $calendarId,
            tokenAcquired: true,
            allowedConferenceTypes: $allowed,
            error: $this->lastDiagnostic,
        );

        return $success;
    }

    private function attemptTemporaryMeetCreation(string $credentials, string $calendarId, string $subject): bool
    {
        $eventId = null;

        try {
            $event = $this->client->insertEvent(
                $credentials,
                $calendarId,
                $this->temporaryEventPayload(),
                $subject,
                1,
                'none',
            );
            $eventId = $event['id'];

            $status = $event['conferenceData']['status'] ?? null;

            for ($attempt = 0; $status === 'pending' && $attempt < self::RECONFIRM_ATTEMPTS; $attempt++) {
                usleep(self::RECONFIRM_DELAY_MICROSECONDS);
                $event = $this->client->getEvent($credentials, $calendarId, $eventId, $subject);
                $status = $event['conferenceData']['status'] ?? null;
            }

            if ($status !== 'success') {
                $this->lastDiagnostic = sprintf(
                    'Google Meet test event did not confirm successfully (conference status: %s).',
                    $status ?? 'unknown',
                );

                return false;
            }

            return true;
        } catch (Throwable $e) {
            $this->lastDiagnostic = $this->sanitize('Test meeting creation failed: '.$e->getMessage());

            return false;
        } finally {
            if ($eventId !== null) {
                try {
                    $this->client->deleteEvent($credentials, $calendarId, $eventId, $subject);
                } catch (Throwable) {
                    // Best-effort cleanup only — the outcome above is already diagnosed.
                }
            }
        }
    }

    /** @return array<string, mixed> */
    private function temporaryEventPayload(): array
    {
        $start = CarbonImmutable::now()->addWeek();

        return [
            'summary' => 'Google Meet configuration test (safe to ignore)',
            'description' => 'Automated Google Meet configuration test event — deleted immediately after the check completes.',
            'start' => ['dateTime' => $start->toIso8601String(), 'timeZone' => 'UTC'],
            'end' => ['dateTime' => $start->addMinutes(15)->toIso8601String(), 'timeZone' => 'UTC'],
            'conferenceRequestId' => 'meet-config-test-'.Str::uuid(),
        ];
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
