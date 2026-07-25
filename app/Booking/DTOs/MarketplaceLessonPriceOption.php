<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

/**
 * One bookable duration's resolved, student-facing price — the
 * marketplace read-only mirror of a single StudentLessonPriceResolver
 * match. Never carries the matrix row's internal ID, instructor
 * compensation, or any other non-public field.
 */
final readonly class MarketplaceLessonPriceOption
{
    public function __construct(
        public int $durationMinutes,
        public int $amountMinor,
        public string $formattedAmount,
        public string $currencyCode,
        public bool $isInstructorOverride,
    ) {}
}
