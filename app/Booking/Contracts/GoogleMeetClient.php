<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\Exceptions\GatewayRequestException;

/**
 * Isolates the Google Meet REST API (v2) behind a plain-array seam,
 * exactly as GoogleCalendarClient does for Calendar and
 * GoogleDriveClient does for Drive. Nothing above the gateway ever
 * touches \Google\Service\Meet.
 *
 * Stateless and credential-agnostic like its siblings: the decrypted
 * service-account JSON and the impersonated Workspace subject are
 * passed per call, never stored on the implementation, never logged.
 *
 * SCOPE NOTE, load-bearing. Meet's `meetings.space.created` scope only
 * covers spaces the CALLING APP created through the Meet API. SIRI's
 * meetings are created by the Google Calendar API
 * (conferenceData.createRequest), which makes their spaces
 * Calendar-created, so that scope cannot read them and every call
 * would fail with PERMISSION_DENIED. This integration therefore
 * requires `meetings.space.readonly`. See docs/recordings.md.
 */
interface GoogleMeetClient
{
    /**
     * The single source of truth for the scopes this integration
     * requests — diagnostics and tests read this rather than
     * duplicating scope strings.
     *
     * @return list<string>
     */
    public function requestedScopes(): array;

    /**
     * Conference records for one meeting space, newest first.
     *
     * Looked up by the space's MEETING CODE (the immutable identifier
     * Calendar already gives us as conferenceData.conferenceId) and
     * bounded by a start-time window, because a single space can host
     * many conferences over time. Never matched on title, participant,
     * or a formatted date.
     *
     * @param  string  $meetingCode  e.g. "abc-defg-hjk"
     * @return list<array{name: string, startTime: ?string, endTime: ?string, space: ?string}>
     *
     * @throws GatewayRequestException
     */
    public function listConferenceRecords(
        string $credentialsJson,
        string $delegatedSubject,
        string $meetingCode,
        string $startTimeFrom,
        string $startTimeTo,
    ): array;

    /**
     * Recording artifacts for one conference record.
     *
     * `state` is the readiness signal and matters more than anything
     * else here: STARTED and ENDED mean the file does not exist yet
     * (recording generation is asynchronous), and only FILE_GENERATED
     * carries a usable driveFileId.
     *
     * @param  string  $conferenceRecordName  "conferenceRecords/{id}"
     * @return list<array{name: string, state: ?string, startTime: ?string, endTime: ?string, driveFileId: ?string}>
     *
     * @throws GatewayRequestException
     */
    public function listRecordings(
        string $credentialsJson,
        string $delegatedSubject,
        string $conferenceRecordName,
    ): array;

    /**
     * Exchanges the service-account assertion for an access token and
     * discards it — diagnoses domain-wide delegation health (a missing
     * Meet scope in the Workspace grant) in isolation, before any Meet
     * API request is attempted.
     *
     * @throws GatewayRequestException with a credential-free message
     */
    public function verifyTokenAcquisition(string $credentialsJson, string $delegatedSubject): void;
}
