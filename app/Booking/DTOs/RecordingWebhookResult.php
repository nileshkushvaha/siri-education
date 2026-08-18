<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

/**
 * A verified recording webhook, reduced to the only three things the
 * application needs: which provider, which delivery (for replay
 * protection), and which meeting.
 *
 * Note what is absent: no download URL, no download token, no file
 * list, no raw payload. The webhook is treated purely as a SIGNAL that
 * something is ready — the artifact itself is re-fetched from the
 * provider's API during ingestion, server-side, over an authenticated
 * call. That keeps short-lived credentials out of the database and
 * makes a replayed webhook worthless to anyone who intercepts it.
 *
 * `eventId` must be deterministic for a given delivery so that the
 * unique index on (provider, provider_event_id) recognises a redelivery
 * as the same event.
 */
final readonly class RecordingWebhookResult
{
    public function __construct(
        public string $provider,
        public string $eventId,
        public string $eventType,
        /** The provider's own meeting identifier, used to find the BookingMeeting. */
        public ?string $meetingReference,
        /** True when this event means "a recording is ready to ingest". */
        public bool $recordingReady,
    ) {}
}
