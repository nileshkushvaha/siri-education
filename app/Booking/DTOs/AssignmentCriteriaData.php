<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use Carbon\CarbonImmutable;

/**
 * What the guest/student needs — never which teacher they want.
 * The assignment engine turns this into a teacher.
 */
final readonly class AssignmentCriteriaData
{
    public function __construct(
        public string $typeKey,
        public string $subject,
        public int $grade,
        public CarbonImmutable $startsAt,
        public int $durationMinutes,
        public ?string $timezone = null,
        public ?string $language = null, // reserved for future language matching
    ) {}

    public function endsAt(): CarbonImmutable
    {
        return $this->startsAt->addMinutes($this->durationMinutes);
    }
}
