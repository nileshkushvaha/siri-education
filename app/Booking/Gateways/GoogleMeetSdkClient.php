<?php

declare(strict_types=1);

namespace App\Booking\Gateways;

use App\Booking\Contracts\GoogleMeetClient;
use App\Booking\Exceptions\GatewayRequestException;
use Google\Client;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\Meet;
use Google\Service\Meet\ArtifactConfig;
use Google\Service\Meet\RecordingConfig;
use Google\Service\Meet\Space;
use Google\Service\Meet\SpaceConfig;
use GuzzleHttp\Exception\RequestException;
use Throwable;

/**
 * Wraps the Google Meet REST API v2 — the only class in this codebase
 * that ever instantiates \Google\Service\Meet. Everything above it
 * sees plain arrays, exactly as with GoogleCalendarSdkClient and
 * GoogleDriveSdkClient.
 *
 * No new dependency: the Meet service already ships inside the
 * google/apiclient-services package this project uses for Calendar and
 * Drive.
 *
 * SCOPES, verified against the live API rather than assumed:
 * `meetings.space.readonly` reads conference records of every space the
 * impersonated account can see (including the Calendar-created spaces
 * of older lessons); `meetings.space.created` + `meetings.space.settings`
 * create a lesson's space through the Meet API with automatic recording
 * configured. Reads and creation use separate tokens — see READ_SCOPES /
 * SPACE_SCOPES below and docs/recordings.md §3/§6.
 */
final class GoogleMeetSdkClient implements GoogleMeetClient
{
    /**
     * Read and create scopes, requested on SEPARATE tokens:
     *
     *  meetings.space.readonly  read conferenceRecords/recordings of any
     *                           space the account can see — including the
     *                           Calendar-created spaces of older lessons
     *  meetings.space.created   create a space through the Meet API
     *  meetings.space.settings  set that space's config (auto-recording)
     *
     * A delegation grant that lacks the create/settings scopes would
     * otherwise fail token acquisition for the read scope too, silently
     * breaking recording discovery for every lesson. Reads use the
     * read-only token; createSpace() uses the space token and fails on
     * its own, which the provider turns into a Calendar-created
     * conference without auto-recording. Any change here must be
     * mirrored exactly in the Workspace delegation grant.
     */
    private const READ_SCOPES = [Meet::MEETINGS_SPACE_READONLY];

    /**
     * spaces.create itself needs meetings.space.created (verified against
     * the live API: settings alone answers 403 insufficientPermissions
     * even for an unconfigured space); the auto-recording config on that
     * space needs meetings.space.settings. Both go on the one token used
     * for space creation.
     */
    private const SPACE_SCOPES = [Meet::MEETINGS_SPACE_CREATED, Meet::MEETINGS_SPACE_SETTINGS];

    private const REQUESTED_SCOPES = [Meet::MEETINGS_SPACE_READONLY, Meet::MEETINGS_SPACE_CREATED, Meet::MEETINGS_SPACE_SETTINGS];

    /** The scope set of the token most recently minted — for credential-free diagnostics only. */
    private array $activeScopes = self::READ_SCOPES;

    /** Google coerces anything above this; stated explicitly so paging is bounded by intent, not by a default. */
    private const MAX_PAGE_SIZE = 100;

    /** Hard stop on paging, so a pathological response can never loop this worker indefinitely. */
    private const MAX_PAGES = 5;

    public function requestedScopes(): array
    {
        return self::REQUESTED_SCOPES;
    }

    public function verifyTokenAcquisition(string $credentialsJson, string $delegatedSubject): void
    {
        try {
            // Both grants, each on its own token, so the diagnostic names
            // exactly which scope the delegation entry is missing.
            $this->service($credentialsJson, $delegatedSubject, self::READ_SCOPES);
            $this->service($credentialsJson, $delegatedSubject, self::SPACE_SCOPES);
        } catch (GatewayRequestException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new GatewayRequestException($e->getMessage(), previous: $e);
        }
    }

    public function createSpace(string $credentialsJson, string $delegatedSubject, bool $autoRecording): array
    {
        try {
            $space = new Space;

            if ($autoRecording) {
                $recording = new RecordingConfig;
                $recording->setAutoRecordingGeneration('ON');
                $artifacts = new ArtifactConfig;
                $artifacts->setRecordingConfig($recording);
                $config = new SpaceConfig;
                $config->setArtifactConfig($artifacts);
                $space->setConfig($config);
            }

            $created = $this->service($credentialsJson, $delegatedSubject, self::SPACE_SCOPES)->spaces->create($space);

            $name = (string) $created->getName();
            $code = (string) $created->getMeetingCode();
            $uri = (string) $created->getMeetingUri();

            if ($name === '' || $code === '' || $uri === '') {
                throw new GatewayRequestException('Google Meet returned an incomplete space (missing name, meeting code or URI).');
            }

            return ['name' => $name, 'meetingCode' => $code, 'meetingUri' => $uri];
        } catch (GatewayRequestException $e) {
            throw $e;
        } catch (GoogleServiceException $e) {
            throw $this->translateApiException($e, $delegatedSubject);
        } catch (Throwable $e) {
            throw new GatewayRequestException($e->getMessage(), previous: $e);
        }
    }

    public function listConferenceRecords(
        string $credentialsJson,
        string $delegatedSubject,
        string $meetingCode,
        string $startTimeFrom,
        string $startTimeTo,
    ): array {
        // Filtered on the space's own meeting code plus an explicit
        // time window: a space can host many conferences over its
        // lifetime, so the code alone does not identify one class.
        $filter = sprintf(
            'space.meeting_code = "%s" AND start_time >= "%s" AND start_time <= "%s"',
            $this->escapeFilterValue($meetingCode),
            $this->escapeFilterValue($startTimeFrom),
            $this->escapeFilterValue($startTimeTo),
        );

        return $this->paginate(
            $credentialsJson,
            $delegatedSubject,
            fn (Meet $meet, ?string $pageToken): array => $this->conferenceRecordPage($meet, $filter, $pageToken),
        );
    }

    public function listRecordings(
        string $credentialsJson,
        string $delegatedSubject,
        string $conferenceRecordName,
    ): array {
        return $this->paginate(
            $credentialsJson,
            $delegatedSubject,
            fn (Meet $meet, ?string $pageToken): array => $this->recordingPage($meet, $conferenceRecordName, $pageToken),
        );
    }

    // ── Internals ──────────────────────────────────────────────────────

    /**
     * @param  callable(Meet, ?string): array{items: list<array<string, mixed>>, nextPageToken: ?string}  $fetch
     * @return list<array<string, mixed>>
     */
    private function paginate(string $credentialsJson, string $delegatedSubject, callable $fetch): array
    {
        try {
            $meet = $this->service($credentialsJson, $delegatedSubject);
            $items = [];
            $pageToken = null;

            for ($page = 0; $page < self::MAX_PAGES; $page++) {
                ['items' => $pageItems, 'nextPageToken' => $pageToken] = $fetch($meet, $pageToken);
                $items = [...$items, ...$pageItems];

                if ($pageToken === null || $pageToken === '') {
                    break;
                }
            }

            return $items;
        } catch (GatewayRequestException $e) {
            throw $e;
        } catch (GoogleServiceException $e) {
            throw $this->translateApiException($e, $delegatedSubject);
        } catch (Throwable $e) {
            throw new GatewayRequestException($e->getMessage(), previous: $e);
        }
    }

    /** @return array{items: list<array<string, mixed>>, nextPageToken: ?string} */
    private function conferenceRecordPage(Meet $meet, string $filter, ?string $pageToken): array
    {
        $params = ['filter' => $filter, 'pageSize' => self::MAX_PAGE_SIZE];

        if ($pageToken !== null) {
            $params['pageToken'] = $pageToken;
        }

        $response = $meet->conferenceRecords->listConferenceRecords($params);

        $items = array_map(static fn ($record): array => [
            'name' => (string) $record->getName(),
            'startTime' => $record->getStartTime(),
            'endTime' => $record->getEndTime(),
            'space' => $record->getSpace(),
        ], $response->getConferenceRecords() ?? []);

        return ['items' => array_values($items), 'nextPageToken' => $response->getNextPageToken()];
    }

    /** @return array{items: list<array<string, mixed>>, nextPageToken: ?string} */
    private function recordingPage(Meet $meet, string $parent, ?string $pageToken): array
    {
        $params = ['pageSize' => self::MAX_PAGE_SIZE];

        if ($pageToken !== null) {
            $params['pageToken'] = $pageToken;
        }

        $response = $meet->conferenceRecords_recordings->listConferenceRecordsRecordings($parent, $params);

        $items = array_map(static fn ($recording): array => [
            'name' => (string) $recording->getName(),
            'state' => $recording->getState(),
            'startTime' => $recording->getStartTime(),
            'endTime' => $recording->getEndTime(),
            // Only ever the Drive FILE ID — never the exportUri, which
            // is a user-facing Drive link this application has no
            // business propagating anywhere.
            'driveFileId' => $recording->getDriveDestination()?->getFile(),
        ], $response->getRecordings() ?? []);

        return ['items' => array_values($items), 'nextPageToken' => $response->getNextPageToken()];
    }

    /**
     * Same construction order and delegation rules as
     * GoogleCalendarSdkClient: auth config → scopes → subject, then an
     * explicit token exchange so a delegation problem is diagnosed
     * here rather than surfacing as an opaque API error later.
     */
    /** @param  list<string>|null  $scopes  defaults to the read-only scope */
    private function service(string $credentialsJson, string $delegatedSubject, ?array $scopes = null): Meet
    {
        $scopes ??= self::READ_SCOPES;
        $this->activeScopes = $scopes;
        $decoded = json_decode($credentialsJson, true, flags: JSON_THROW_ON_ERROR);

        $client = new Client;
        $client->setApplicationName(config('app.name', 'Enterprise App').' Meet Recordings');
        $client->setAuthConfig($decoded);
        $client->setScopes($scopes);
        $client->setSubject($delegatedSubject);

        $this->assertTokenAcquired($client, $decoded, $delegatedSubject);

        return new Meet($client);
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

    /**
     * Extracts only the OAuth error fields from the response body —
     * never the request, which carries the signed JWT assertion, and
     * never the raw exception message.
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

        return new GatewayRequestException(
            $this->tokenErrorMessage($error, $description, $decodedCredentials, $delegatedSubject),
            previous: $e,
        );
    }

    /** @param  array<string, mixed>  $decodedCredentials */
    private function tokenErrorMessage(string $error, ?string $description, array $decodedCredentials, string $delegatedSubject): string
    {
        return implode('. ', array_filter([
            sprintf('Google Meet OAuth token error: %s', $error),
            $description !== null ? sprintf('Description: %s', $description) : null,
            sprintf('Client email: %s', $decodedCredentials['client_email'] ?? 'unknown'),
            sprintf('Delegated subject: %s', $delegatedSubject),
            sprintf('Requested scopes: [%s]', implode(', ', $this->activeScopes)),
        ], static fn (?string $part): bool => $part !== null));
    }

    private function translateApiException(GoogleServiceException $e, string $delegatedSubject): GatewayRequestException
    {
        $errors = $e->getErrors();
        $reason = $errors[0]['reason'] ?? 'unknown';
        $message = $errors[0]['message'] ?? $e->getMessage();

        return new GatewayRequestException(implode('. ', [
            sprintf('Google Meet API error (HTTP %d, reason: %s): %s', $e->getCode(), $reason, $message),
            sprintf('Delegated account: %s', $delegatedSubject),
            sprintf('Requested scopes: [%s]', implode(', ', $this->activeScopes)),
        ]), previous: $e);
    }

    /**
     * Meet's filter grammar is a quoted-string EBNF; a stray quote in a
     * value would otherwise change the meaning of the expression. Values
     * reaching here are provider-issued identifiers and ISO timestamps,
     * so this is defence in depth rather than a known hole.
     */
    private function escapeFilterValue(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }
}
