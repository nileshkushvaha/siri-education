<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use Carbon\CarbonImmutable;

/**
 * Input to MeetingProviderInterface::updateMeeting() — an admin editing
 * an existing meeting record (manual link change) or a retry/sync
 * attempt (Google). Every field is optional: null means "leave
 * unchanged", not "clear".
 */
final readonly class MeetingUpdateContext
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

    /** The same intent re-expressed as a first creation — used when no meeting row exists yet. */
    public function toCreationContext(): MeetingCreationContext
    {
        return new MeetingCreationContext(
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
