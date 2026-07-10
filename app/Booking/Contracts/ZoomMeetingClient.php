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
     * Prove the stored Server-to-Server OAuth credentials can actually
     * mint an access token. The only method an admin-facing validation
     * action may call; never invoked on ordinary page loads. Returns
     * false rather than throwing — validation failure is an expected
     * outcome, not an error path.
     */
    public function validateCredentials(): bool;
}
