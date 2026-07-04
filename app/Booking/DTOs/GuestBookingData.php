<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use Carbon\CarbonImmutable;

/**
 * A guest's booking request. No teacher and no duration — the
 * assignment engine picks the teacher, the booking type fixes the
 * duration.
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
    ) {}
}
