<?php

declare(strict_types=1);

namespace App\Booking\Gateways;

use App\Booking\Contracts\GoogleCalendarClient;
use App\Booking\Exceptions\GatewayRequestException;
use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\ConferenceData;
use Google\Service\Calendar\ConferenceSolution;
use Google\Service\Calendar\ConferenceSolutionKey;
use Google\Service\Calendar\CreateConferenceRequest;
use Google\Service\Calendar\EntryPoint;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use Google\Service\Exception as GoogleServiceException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Wraps the official `google/apiclient` SDK — the only class in this
 * codebase that ever instantiates \Google\Client or
 * \Google\Service\Calendar. Everything above GoogleCalendarMeetProvider
 * stays SDK-free and only ever sees plain arrays.
 *
 * Stateless/credential-agnostic like RazorpaySdkClient: the decrypted
 * service-account JSON is passed in per call, never stored on this
 * class or logged.
 *
 * Domain-wide delegation: a bare service account is the data owner of
 * nothing and has no Google Meet entitlement, so every call
 * impersonates the configured Workspace user (setSubject) before the
 * Calendar service is built — never construct \Google\Service\Calendar
 * without calling setSubject() first. Requests exactly one OAuth
 * scope (Calendar::CALENDAR) — the domain-wide delegation grant in the
 * Workspace admin console must cover this scope exactly, or every call
 * fails at the token step with `401 unauthorized_client`, before ever
 * reaching a Calendar API request.
 */
final class GoogleCalendarSdkClient implements GoogleCalendarClient
{
    /**
     * The literal value Google's API requires for a Meet conference
     * request — case-sensitive, and deliberately never derived from
     * MeetingProvider/GoogleCalendarMeetProvider::KEY ('google_meet'),
     * which is this codebase's internal enum value, not a Google API
     * constant.
     */
    private const GOOGLE_MEET_CONFERENCE_TYPE = 'hangoutsMeet';

    /**
     * The only scope this integration ever requests. Do not add
     * calendar.events, calendar.readonly, or any other scope here
     * unless the exact same scope is also added to the domain-wide
     * delegation authorization in the Workspace admin console — a
     * mismatch fails token acquisition with `401 unauthorized_client`
     * for every scope, not just the extra one.
     */
    private const REQUESTED_SCOPES = [Calendar::CALENDAR];

    public function insertEvent(
        string $credentialsJson,
        string $calendarId,
        array $eventPayload,
        string $delegatedSubject,
        int $conferenceDataVersion = 1,
        string $sendUpdates = 'all',
    ): array {
        try {
            $service = $this->service($credentialsJson, $delegatedSubject);
            $event = $service->events->insert(
                $calendarId,
                $this->buildEvent($eventPayload),
                ['conferenceDataVersion' => $conferenceDataVersion, 'sendUpdates' => $sendUpdates],
            );

            return $this->toArray($event);
        } catch (GatewayRequestException $e) {
            throw $e;
        } catch (GoogleServiceException $e) {
            throw $this->translateApiException($e, $calendarId, $delegatedSubject, $credentialsJson);
        } catch (Throwable $e) {
            throw new GatewayRequestException($e->getMessage(), previous: $e);
        }
    }

    public function updateEvent(
        string $credentialsJson,
        string $calendarId,
        string $eventId,
        array $eventPayload,
        string $delegatedSubject,
        int $conferenceDataVersion = 1,
        string $sendUpdates = 'all',
    ): array {
        try {
            $service = $this->service($credentialsJson, $delegatedSubject);
            $event = $service->events->update(
                $calendarId,
                $eventId,
                $this->buildEvent($eventPayload),
                ['conferenceDataVersion' => $conferenceDataVersion, 'sendUpdates' => $sendUpdates],
            );

            return $this->toArray($event);
        } catch (GatewayRequestException $e) {
            throw $e;
        } catch (GoogleServiceException $e) {
            throw $this->translateApiException($e, $calendarId, $delegatedSubject, $credentialsJson);
        } catch (Throwable $e) {
            throw new GatewayRequestException($e->getMessage(), previous: $e);
        }
    }

    public function deleteEvent(string $credentialsJson, string $calendarId, string $eventId, string $delegatedSubject): void
    {
        try {
            $this->service($credentialsJson, $delegatedSubject)->events->delete($calendarId, $eventId);
        } catch (GatewayRequestException $e) {
            throw $e;
        } catch (GoogleServiceException $e) {
            throw $this->translateApiException($e, $calendarId, $delegatedSubject, $credentialsJson);
        } catch (Throwable $e) {
            throw new GatewayRequestException($e->getMessage(), previous: $e);
        }
    }

    public function requestedScopes(): array
    {
        return self::REQUESTED_SCOPES;
    }

    public function verifyTokenAcquisition(string $credentialsJson, string $delegatedSubject): void
    {
        try {
            $this->service($credentialsJson, $delegatedSubject);
        } catch (GatewayRequestException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new GatewayRequestException($e->getMessage(), previous: $e);
        }
    }

    public function getEvent(string $credentialsJson, string $calendarId, string $eventId, string $delegatedSubject): array
    {
        try {
            $event = $this->service($credentialsJson, $delegatedSubject)->events->get($calendarId, $eventId);

            return $this->toArray($event);
        } catch (GatewayRequestException $e) {
            throw $e;
        } catch (GoogleServiceException $e) {
            throw $this->translateApiException($e, $calendarId, $delegatedSubject, $credentialsJson);
        } catch (Throwable $e) {
            throw new GatewayRequestException($e->getMessage(), previous: $e);
        }
    }

    public function allowedConferenceTypes(string $credentialsJson, string $calendarId, string $delegatedSubject): array
    {
        try {
            $calendar = $this->service($credentialsJson, $delegatedSubject)->calendars->get($calendarId);

            return $calendar->getConferenceProperties()?->getAllowedConferenceSolutionTypes() ?? [];
        } catch (GatewayRequestException $e) {
            throw $e;
        } catch (GoogleServiceException $e) {
            throw $this->translateApiException($e, $calendarId, $delegatedSubject, $credentialsJson);
        } catch (Throwable $e) {
            throw new GatewayRequestException($e->getMessage(), previous: $e);
        }
    }

    /**
     * Construction order matters, exactly: setAuthConfig(), then
     * setScopes(), then setSubject() — unconditionally, never skipped —
     * and only then is \Google\Service\Calendar instantiated. Before
     * handing back the Calendar service, explicitly exchanges the
     * service-account assertion for an access token
     * (fetchAccessTokenWithAssertion) so an OAuth delegation failure
     * (wrong/missing domain-wide delegation scope, revoked key) is
     * diagnosed here — safely, with no secret material — rather than
     * surfacing as an opaque failure from whichever Calendar API call
     * happens to run first.
     */
    private function service(string $credentialsJson, string $delegatedSubject): Calendar
    {
        $decoded = json_decode($credentialsJson, true, flags: JSON_THROW_ON_ERROR);
        $client = $this->buildClient($decoded, $delegatedSubject);

        $this->assertTokenAcquired($client, $decoded, $delegatedSubject);

        return new Calendar($client);
    }

    /**
     * Isolated from token acquisition so construction order (auth
     * config → scopes → subject) is independently verifiable — this
     * step never performs I/O.
     *
     * @param  array<string, mixed>  $decodedCredentials
     */
    private function buildClient(array $decodedCredentials, string $delegatedSubject): Client
    {
        $client = new Client;
        $client->setApplicationName(config('app.name', 'Enterprise App').' Meetings');
        $client->setAuthConfig($decodedCredentials);
        $client->setScopes(self::REQUESTED_SCOPES);
        $client->setSubject($delegatedSubject);

        return $client;
    }

    /** @param  array<string, mixed>  $decodedCredentials */
    private function assertTokenAcquired(Client $client, array $decodedCredentials, string $delegatedSubject): void
    {
        try {
            $token = $client->fetchAccessTokenWithAssertion();
        } catch (Throwable $e) {
            throw $this->translateTokenFailure($e, $decodedCredentials, $delegatedSubject);
        }

        if (is_array($token) && isset($token['error'])) {
            throw new GatewayRequestException($this->tokenErrorMessage(
                (string) $token['error'],
                isset($token['error_description']) ? (string) $token['error_description'] : null,
                $decodedCredentials,
                $delegatedSubject,
            ));
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function buildEvent(array $payload): Event
    {
        $event = new Event;
        $event->setSummary($payload['summary']);
        $event->setDescription($payload['description']);

        $start = new EventDateTime;
        $start->setDateTime($payload['start']['dateTime']);
        $start->setTimeZone($payload['start']['timeZone']);
        $event->setStart($start);

        $end = new EventDateTime;
        $end->setDateTime($payload['end']['dateTime']);
        $end->setTimeZone($payload['end']['timeZone']);
        $event->setEnd($end);

        if (isset($payload['location'])) {
            $event->setLocation((string) $payload['location']);
        }

        $solutionKey = new ConferenceSolutionKey;
        $solutionKey->setType(self::GOOGLE_MEET_CONFERENCE_TYPE);

        $conferenceData = new ConferenceData;

        if (isset($payload['attachConference'])) {
            // An EXISTING Meet space (created through the Meet API so it
            // can carry auto-recording) attached to the event, rather
            // than a new Calendar-created conference.
            $attach = $payload['attachConference'];

            $solution = new ConferenceSolution;
            $solution->setKey($solutionKey);

            $entryPoint = new EntryPoint;
            $entryPoint->setEntryPointType('video');
            $entryPoint->setUri((string) $attach['meetingUri']);
            $entryPoint->setLabel(preg_replace('#^https?://#', '', (string) $attach['meetingUri']) ?: null);

            $conferenceData->setConferenceId((string) $attach['meetingCode']);
            $conferenceData->setConferenceSolution($solution);
            $conferenceData->setEntryPoints([$entryPoint]);
        } elseif (! array_key_exists('conferenceRequestId', $payload) && isset($payload['location'])) {
            // Fallback event with the Meet link as location only — no
            // conference requested.
            return $event;
        } else {
            $createRequest = new CreateConferenceRequest;
            $createRequest->setRequestId($payload['conferenceRequestId'] ?? Str::uuid()->toString());
            $createRequest->setConferenceSolutionKey($solutionKey);
            $conferenceData->setCreateRequest($createRequest);
        }

        $event->setConferenceData($conferenceData);

        return $event;
    }

    /**
     * Converts the SDK's Event object to a plain, minimal array
     * immediately — no raw SDK object or full API response ever
     * propagates past this method.
     *
     * @return array{id: string, hangoutLink: ?string, conferenceData: array<string, mixed>}
     */
    private function toArray(Event $event): array
    {
        $conferenceData = $event->getConferenceData();

        return [
            'id' => (string) $event->getId(),
            'hangoutLink' => $event->getHangoutLink(),
            'conferenceData' => $conferenceData !== null ? [
                'conferenceId' => $conferenceData->getConferenceId(),
                'status' => $conferenceData->getCreateRequest()?->getStatus()?->getStatusCode(),
                'entryPoints' => array_map(
                    static fn ($entryPoint): array => [
                        'entryPointType' => $entryPoint->getEntryPointType(),
                        'uri' => $entryPoint->getUri(),
                    ],
                    $conferenceData->getEntryPoints() ?? [],
                ),
            ] : [],
        ];
    }

    /**
     * Builds a safe OAuth-token-failure diagnostic — HTTP-level errors
     * (e.g. Google's `401 unauthorized_client` when the domain-wide
     * delegation grant doesn't cover the requested scope) surface as a
     * thrown Guzzle RequestException, not as a returned array; this
     * extracts only the response body's `error`/`error_description`
     * fields, never the request (which carries the signed JWT
     * assertion) and never $e->getMessage() verbatim.
     *
     * @param  array<string, mixed>  $decodedCredentials
     */
    private function translateTokenFailure(Throwable $e, array $decodedCredentials, string $delegatedSubject): GatewayRequestException
    {
        $error = 'token_request_failed';
        $description = null;

        if ($e instanceof RequestException && $e->hasResponse()) {
            $body = json_decode((string) $e->getResponse()->getBody(), true);

            if (is_array($body)) {
                $error = (string) ($body['error'] ?? $error);
                $description = isset($body['error_description']) ? (string) $body['error_description'] : null;
            }
        }

        return new GatewayRequestException($this->tokenErrorMessage($error, $description, $decodedCredentials, $delegatedSubject), previous: $e);
    }

    /** @param  array<string, mixed>  $decodedCredentials */
    private function tokenErrorMessage(string $error, ?string $description, array $decodedCredentials, string $delegatedSubject): string
    {
        $parts = [
            sprintf('Google OAuth token error: %s', $error),
            $description !== null ? sprintf('Description: %s', $description) : null,
            sprintf('Client ID: %s', $decodedCredentials['client_id'] ?? 'unknown'),
            sprintf('Client email: %s', $decodedCredentials['client_email'] ?? 'unknown'),
            sprintf('Delegated subject: %s', $delegatedSubject),
            sprintf('Requested scopes: [%s]', implode(', ', self::REQUESTED_SCOPES)),
        ];

        return implode('. ', array_filter($parts, static fn (?string $part): bool => $part !== null));
    }

    /**
     * Builds a safe, structured diagnostic message from a Calendar-API
     * (post-authentication) failure — HTTP status, Google's
     * reason/message, calendar id, delegated account, and requested
     * conference type, so an admin reading booking_meetings.failure_reason
     * (or the audit log) can actually act on it without ever seeing
     * credentials or tokens. When the failure looks conference-type
     * related, best-effort appends the calendar's allowed conference
     * types — a second API call that must never itself throw over the
     * original failure.
     */
    private function translateApiException(
        GoogleServiceException $e,
        string $calendarId,
        string $delegatedSubject,
        string $credentialsJson,
    ): GatewayRequestException {
        $errors = $e->getErrors();
        $reason = $errors[0]['reason'] ?? 'unknown';
        $message = $errors[0]['message'] ?? $e->getMessage();

        $parts = [
            sprintf('Google Calendar API error (HTTP %d, reason: %s): %s', $e->getCode(), $reason, $message),
            sprintf('Calendar: %s', $calendarId),
            sprintf('Delegated account: %s', $delegatedSubject),
            sprintf('Requested conference type: %s', self::GOOGLE_MEET_CONFERENCE_TYPE),
        ];

        if (str_contains(strtolower($message), 'conference')) {
            try {
                $allowed = $this->service($credentialsJson, $delegatedSubject)->calendars->get($calendarId)
                    ->getConferenceProperties()?->getAllowedConferenceSolutionTypes() ?? [];
                $parts[] = sprintf('Allowed conference types: [%s]', implode(', ', $allowed) ?: 'none');
            } catch (Throwable) {
                // Best-effort enrichment only — never let a secondary
                // lookup mask the original, already-diagnosed failure.
            }
        }

        return new GatewayRequestException(implode('. ', $parts), previous: $e);
    }
}
