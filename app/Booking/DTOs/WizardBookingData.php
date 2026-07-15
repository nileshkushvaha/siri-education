<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use Carbon\CarbonImmutable;

/**
 * An authenticated student's wizard booking request (Phase 17U.3 —
 * renamed from the pre-authenticated-only "guest booking" DTO). The
 * student identity always comes from the authenticated session, never
 * from this payload. Most wizard bookings have no teacher and the
 * assignment engine picks one; profile-launched bookings may lock a
 * specific teacher while still using the same eligibility and
 * availability checks.
 */
final readonly class WizardBookingData
{
    public function __construct(
        public string $typeKey,
        public string $subject,
        public int $grade,
        public CarbonImmutable $startsAt,
        public string $timezone,
        public ?string $notes = null,
        public ?int $teacherId = null,
    ) {}
}
