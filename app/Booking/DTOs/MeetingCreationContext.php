<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use Carbon\CarbonImmutable;

/**
 * Input to MeetingProviderInterface::createMeeting(). Every field is
 * optional — a provider falls back to the booking's own
 * starts_at/ends_at/timezone when not overridden. `providerLabel` is
 * only meaningful to ManualMeetingProvider (e.g. "zoom_manual") and is
 * stored in the resulting meeting's metadata, never as the `provider`
 * column value.
 */
final readonly class MeetingCreationContext
{
    public function __construct(
        public ?int $requestedBy = null,
        public ?string $providerLabel = null,
        public ?string $joinUrl = null,
        public ?string $password = null,
        public ?CarbonImmutable $startsAt = null,
        public ?CarbonImmutable $endsAt = null,
        public ?string $timezone = null,
    ) {}

    /** The same intent re-expressed as a retry/update — used when a pending/failed row already exists. */
    public function toUpdateContext(): MeetingUpdateContext
    {
        return new MeetingUpdateContext(
            requestedBy: $this->requestedBy,
            providerLabel: $this->providerLabel,
            joinUrl: $this->joinUrl,
            password: $this->password,
            startsAt: $this->startsAt,
            endsAt: $this->endsAt,
            timezone: $this->timezone,
        );
    }
}
