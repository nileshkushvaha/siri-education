<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\Exceptions\GatewayRequestException;

/**
 * Isolation seam for the Zoom REST API — the only boundary Zoom HTTP
 * traffic may cross. ZoomMeetingProvider never builds a request or
 * touches an access token directly, and tests bind a fake
 * implementation instead of stubbing HTTP. Mirrors GoogleCalendarClient
 * and the Razorpay/Stripe gateway-client seams.
 *
 * Every array returned is pre-sanitized by the implementation: never a
 * raw Zoom API response, never an access token.
 */
interface ZoomMeetingClient
{
    /**
     * Create a scheduled meeting under the given host user (Zoom user id
     * or email).
     *
     * @param  array<string, mixed>  $payload  Zoom create-meeting body (topic, type, start_time, …)
     * @return array{id: string, join_url: ?string, start_url: ?string, password: ?string, timezone: ?string, status: ?string}
     *
     * @throws GatewayRequestException
     */
    public function createMeeting(string $hostUser, array $payload): array;

    /**
     * Update an existing meeting and return its fresh sanitized state.
     *
     * @param  array<string, mixed>  $payload
     * @return array{id: string, join_url: ?string, start_url: ?string, password: ?string, timezone: ?string, status: ?string}
     *
     * @throws GatewayRequestException
     */
    public function updateMeeting(string $meetingId, array $payload): array;

    /**
     * Delete/cancel a meeting. An already-deleted meeting (404) counts
     * as success — the goal state is "no meeting", not "we deleted it".
     *
     * @throws GatewayRequestException
     */
    public function deleteMeeting(string $meetingId): bool;

    /**
     * The cloud-recording files Zoom holds for one meeting — the
     * reconciliation counterpart of the recording.completed webhook, so
     * a missed or undelivered event never permanently loses a class
     * recording.
     *
     * Returns null when Zoom has no recording for the meeting (404),
     * which is an ordinary "not ready / never recorded" answer rather
     * than a failure. Each file is already sanitized: no download
     * token, no account data, no raw payload.
     *
     * @param  string  $meetingId  the numeric Zoom meeting id, or a UUID for a past occurrence
     * @return array{uuid: ?string, files: list<array{id: string, file_type: ?string, file_extension: ?string, recording_type: ?string, status: ?string, file_size: ?int, recording_start: ?string, recording_end: ?string, download_url: ?string}>}|null
     *
     * @throws GatewayRequestException
     */
    public function listMeetingRecordings(string $meetingId): ?array;

    /**
     * Opens an authenticated read stream for one cloud-recording file.
     *
     * $downloadUrl MUST be a Zoom-issued URL — implementations reject
     * any other host, so a value that ever reached the database could
     * not turn this into an arbitrary server-side fetcher.
     *
     * $downloadToken is the short-lived token Zoom includes with a
     * recording webhook (valid ~24h). When absent the implementation
     * falls back to the Server-to-Server access token. Neither is ever
     * persisted or logged.
     *
     * @return resource
     *
     * @throws GatewayRequestException
     */
    public function openRecordingStream(string $downloadUrl, ?string $downloadToken = null);

    /**
     * Prove the stored Server-to-Server OAuth credentials can actually
     * mint an access token. The only method an admin-facing validation
     * action may call; never invoked on ordinary page loads. Returns
     * false rather than throwing — validation failure is an expected
     * outcome, not an error path.
     */
    public function validateCredentials(): bool;
}
