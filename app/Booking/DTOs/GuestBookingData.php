<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use Carbon\CarbonImmutable;

/**
 * A guest's booking request. Most guest bookings have no teacher and
 * the assignment engine picks one. Profile-launched bookings may lock
 * a specific teacher while still using the same eligibility and
 * availability checks.
 */
final readonly class GuestBookingData
{
    public function __construct(
        public string $typeKey,
        public string $subject,
        public int $grade,
        public CarbonImmutable $startsAt,
        public string $timezone,
        public string $guestName,
        public string $guestEmail,
        public ?string $guestPhone = null,
        public ?string $notes = null,
        public ?int $teacherId = null,
    ) {}
}
