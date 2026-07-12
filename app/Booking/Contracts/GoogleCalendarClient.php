<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\Exceptions\GatewayRequestException;

/**
 * Isolates the google/apiclient SDK behind a plain-array seam —
 * GoogleCalendarMeetProvider never touches \Google\Client or
 * \Google\Service\Calendar directly. Tests bind a fake implementation
 * instead of stubbing HTTP, matching RazorpayGatewayClient/
 * StripeGatewayClient's isolation pattern. Every method returns/accepts
 * plain arrays — never the SDK's Event object — so no raw SDK payload
 * ever reaches persistence.
 */
interface GoogleCalendarClient
{
    /**
     * $credentialsJson is the decrypted service-account JSON — this
     * client is stateless/credential-agnostic (mirrors
     * RazorpayGatewayClient::createOrder($keyId, $keySecret, …)); the
     * provider decrypts MeetingSettings::google_credentials_json and
     * passes it per call, never storing it on this class.
     *
     * $delegatedSubject is the Workspace user the service account
     * impersonates via domain-wide delegation (MeetingSettings::
     * platform_meeting_account) — required (never optional/skippable):
     * a bare, un-impersonated service account has no Meet entitlement
     * and no access to the platform calendar.
     *
     * @param  array<string, mixed>  $eventPayload
     * @return array{id: string, hangoutLink: ?string, conferenceData: array<string, mixed>}
     */
    public function insertEvent(
        string $credentialsJson,
        string $calendarId,
        array $eventPayload,
        string $delegatedSubject,
        int $conferenceDataVersion = 1,
        string $sendUpdates = 'all',
    ): array;

    /**
     * @param  array<string, mixed>  $eventPayload
     * @return array{id: string, hangoutLink: ?string, conferenceData: array<string, mixed>}
     */
    public function updateEvent(
        string $credentialsJson,
        string $calendarId,
        string $eventId,
        array $eventPayload,
        string $delegatedSubject,
        int $conferenceDataVersion = 1,
        string $sendUpdates = 'all',
    ): array;

    public function deleteEvent(string $credentialsJson, string $calendarId, string $eventId, string $delegatedSubject): void;

    /**
     * Exchanges the service-account assertion for an access token and
     * discards it — tests OAuth/domain-wide-delegation health (wrong or
     * missing scope authorization, revoked/rotated key) in isolation,
     * before any actual Calendar API request is attempted.
     *
     * @throws GatewayRequestException with a
     *                                 safe, credential-free message (error, error_description,
     *                                 client_id, client_email, delegated subject, scopes) when
     *                                 token acquisition fails.
     */
    public function verifyTokenAcquisition(string $credentialsJson, string $delegatedSubject): void;

    /**
     * The single source of truth for the OAuth scopes this integration
     * requests — callers (diagnostics, tests) read this instead of
     * duplicating the literal scope string.
     *
     * @return list<string>
     */
    public function requestedScopes(): array;

    /**
     * Re-fetches a single event — used to poll an asynchronously
     * created Meet conference until Google reports success/failure.
     *
     * @return array{id: string, hangoutLink: ?string, conferenceData: array<string, mixed>}
     */
    public function getEvent(string $credentialsJson, string $calendarId, string $eventId, string $delegatedSubject): array;

    /**
     * The conference solution types the target calendar actually
     * supports (e.g. ['hangoutsMeet']) — read from
     * calendars.get(...).conferenceProperties.allowedConferenceSolutionTypes.
     * An empty array means the calendar/account cannot create any
     * conference, Meet included.
     *
     * @return list<string>
     */
    public function allowedConferenceTypes(string $credentialsJson, string $calendarId, string $delegatedSubject): array;
}
