<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use App\Booking\Enums\BookingLocationType;
use Carbon\CarbonImmutable;

/**
 * Immutable input for requesting a booking. Built by FormRequests (HTTP)
 * or callers such as console commands — never inside Services. Every
 * booking has an authenticated student (Phase 17U.3 — no unauthenticated
 * guest booking concept exists anywhere in this domain).
 */
final readonly class CreateBookingData
{
    /** @param array<string, mixed> $meta type-specific payload (e.g. webinar topic) */
    public function __construct(
        public string $typeKey,
        public int $studentId,
        public int $instructorId,
        public CarbonImmutable $startsAt,
        public int $durationMinutes,
        public BookingLocationType $locationType = BookingLocationType::Online,
        public string $timezone = 'UTC',
        public ?string $notes = null,
        public array $meta = [],
    ) {}

    public function endsAt(): CarbonImmutable
    {
        return $this->startsAt->addMinutes($this->durationMinutes);
    }
}
