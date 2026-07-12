<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

/**
 * A verified, normalized attendance webhook envelope: the provider's
 * own event id (dedup key) plus the normalized attendance events it
 * carries. Raw payloads never travel past the adapter that built this.
 */
final readonly class ProviderAttendanceWebhook
{
    /** @param list<ProviderAttendanceEvent> $events */
    public function __construct(
        public string $provider,
        public string $providerEventId,
        public string $meetingReference,
        public array $events,
    ) {}
}
