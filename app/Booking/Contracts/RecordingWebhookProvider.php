<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\DTOs\RecordingWebhookResult;
use App\Booking\Exceptions\InvalidRecordingWebhookException;
use Illuminate\Http\Request;

/**
 * An OPTIONAL capability for providers that can PUSH a
 * recording-is-ready notification, rather than only answering when
 * asked.
 *
 * Google Meet does not implement this — Google offers no such webhook
 * without Pub/Sub, so Meet relies on the bounded reconciliation sweep.
 * Zoom does, which is why the capability is an interface rather than a
 * method every provider must stub out.
 *
 * A webhook is always an OPTIMIZATION, never the guarantee: reconciliation
 * still runs for every provider, so a webhook Zoom never delivered (or
 * that arrived while the app was down) cannot permanently lose a class
 * recording.
 *
 * Implementations do the minimum trusted work — verify authenticity,
 * identify the meeting — and nothing else. No downloading, no storage,
 * no long-running work: that all belongs on the queue.
 */
interface RecordingWebhookProvider
{
    /** Whether this provider is currently configured to accept recording webhooks. Never performs I/O. */
    public function supportsRecordingWebhooks(): bool;

    /**
     * Authenticity check, run BEFORE the payload is parsed or trusted
     * in any way. Must be constant-time and must not log the secret.
     */
    public function verifyRecordingWebhookSignature(Request $request): bool;

    /**
     * Some providers require an endpoint ownership challenge before
     * they will deliver events (Zoom's endpoint.url_validation). When
     * this request is such a challenge, return the exact body the
     * provider expects; otherwise return null and normal handling
     * continues.
     *
     * @return array<string, mixed>|null
     */
    public function recordingWebhookChallengeResponse(Request $request): ?array;

    /**
     * Normalizes a verified webhook into the provider-neutral shape the
     * application acts on. Must NOT carry download URLs or tokens —
     * those are re-fetched server-side at ingestion time.
     *
     * @throws InvalidRecordingWebhookException on a malformed payload
     */
    public function parseRecordingWebhook(Request $request): RecordingWebhookResult;
}
