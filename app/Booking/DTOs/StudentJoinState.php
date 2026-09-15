<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use App\Booking\Enums\MeetingJoinAvailability;
use Carbon\CarbonImmutable;

/**
 * Everything a student-facing surface needs to render the join action
 * for ONE booking, computed by BookingMeetingService::studentJoinStatesFor()
 * — the same ownership, lifecycle, visibility and time-window predicates
 * as studentJoinUrlFor(), read once per request for a whole list.
 *
 * $joinUrl is the SIRI gateway link (never the provider URL), released
 * only when $availability is Available; the gateway re-checks at click
 * time.
 */
final readonly class StudentJoinState
{
    public function __construct(
        public MeetingJoinAvailability $availability,
        public ?string $joinUrl,
        public ?string $passcode,
        public ?CarbonImmutable $opensAt,
        public ?CarbonImmutable $closesAt,
        /** The answer can still change on its own soon: re-render on a timer. */
        public bool $poll,
        /** The booking's scheduled end has passed. */
        public bool $ended,
    ) {}

    public static function unavailable(bool $ended = false): self
    {
        return new self(MeetingJoinAvailability::Unavailable, null, null, null, null, false, $ended);
    }

    public function isAvailable(): bool
    {
        return $this->availability === MeetingJoinAvailability::Available && $this->joinUrl !== null;
    }
}
