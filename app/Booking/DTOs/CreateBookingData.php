<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use App\Booking\Enums\BookingLocationType;
use App\Booking\Enums\RecurrenceFrequency;
use Carbon\CarbonImmutable;

/**
 * Immutable input for requesting a booking. Built by FormRequests (HTTP)
 * or callers such as console commands — never inside Services. Every
 * booking has an authenticated student — no unauthenticated guest
 * booking concept exists anywhere in this domain.
 */
final readonly class CreateBookingData
{
    /**
     * @param  array<string, mixed>  $meta  type-specific payload (subject, grade, recurring_group, …)
     * @param  RecurrenceFrequency|null  $recurrenceFrequency  Data-provenance field — set only by
     *                                                         the recurring-booking creation path (never inferred later), null for every single/non-recurring
     *                                                         booking. This is the sole authoritative source for reporting's single/daily/weekly classification.
     */
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
        public ?RecurrenceFrequency $recurrenceFrequency = null,
    ) {}

    public function endsAt(): CarbonImmutable
    {
        return $this->startsAt->addMinutes($this->durationMinutes);
    }
}
