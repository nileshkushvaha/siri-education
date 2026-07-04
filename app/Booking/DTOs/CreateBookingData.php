<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use App\Booking\Enums\BookingLocationType;
use Carbon\CarbonImmutable;

/**
 * Immutable input for requesting a booking. Built by FormRequests (HTTP)
 * or callers such as console commands — never inside Services.
 * Null $attendeeId = guest booking; guest identity fields required then.
 */
final readonly class CreateBookingData
{
    /** @param array<string, mixed> $meta type-specific payload (e.g. webinar topic) */
    public function __construct(
        public string $typeKey,
        public ?int $attendeeId,
        public int $hostId,
        public CarbonImmutable $startsAt,
        public int $durationMinutes,
        public BookingLocationType $locationType = BookingLocationType::Online,
        public string $timezone = 'UTC',
        public ?string $notes = null,
        public array $meta = [],
        public ?string $guestName = null,
        public ?string $guestEmail = null,
        public ?string $guestPhone = null,
    ) {}

    public function endsAt(): CarbonImmutable
    {
        return $this->startsAt->addMinutes($this->durationMinutes);
    }

    public function isGuest(): bool
    {
        return $this->attendeeId === null;
    }
}
