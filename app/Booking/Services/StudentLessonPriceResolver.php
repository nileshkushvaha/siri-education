<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\StudentLessonPriceRepositoryInterface;
use App\Booking\Exceptions\BookingException;
use App\Models\AcademicLevel;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\StudentLessonPrice;
use App\Models\Subject;

/**
 * Resolves the admin-managed student-facing price for a paid lesson.
 * Match priority (see docs/architecture/phase-10.2d-student-pricing-matrix.md):
 *
 *   1. instructor-specific + exact academic level
 *   2. instructor-specific + null academic level ("all levels")
 *   3. base price (no instructor) + exact academic level
 *   4. base price (no instructor) + null academic level ("all levels")
 *
 * An instructor-specific price always wins over the base price for
 * that same instructor — it is never used as a tie-breaker or a
 * fallback source for anything, it's a full override. No fallback
 * exists beyond this — a miss always throws, and
 * `BookingPriceCalculator` (the only caller) does not catch it. There
 * is no `booking_types.price`/`currency` fallback — this resolver's
 * output is the sole source of a paid lesson's price.
 */
final class StudentLessonPriceResolver
{
    public function __construct(
        private readonly StudentLessonPriceRepositoryInterface $prices,
    ) {}

    /** @throws BookingException when no active, currently-effective price matches */
    public function resolve(
        BookingType $type,
        Subject $subject,
        ?AcademicLevel $academicLevel,
        int $durationMinutes,
        Country $country,
        ?int $instructorId = null,
    ): StudentLessonPrice {
        if ($instructorId !== null) {
            $match = $this->matchWithLevelFallback($type, $subject, $academicLevel, $durationMinutes, $country, $instructorId);

            if ($match !== null) {
                return $match;
            }
        }

        $match = $this->matchWithLevelFallback($type, $subject, $academicLevel, $durationMinutes, $country, null);

        if ($match !== null) {
            return $match;
        }

        throw new BookingException(sprintf(
            'The "%s" lesson price is not configured yet. Please contact support.',
            $type->name,
        ));
    }

    /** Exact academic level first, then "all levels" — within one instructor scope (specific or base). */
    private function matchWithLevelFallback(
        BookingType $type,
        Subject $subject,
        ?AcademicLevel $academicLevel,
        int $durationMinutes,
        Country $country,
        ?int $instructorId,
    ): ?StudentLessonPrice {
        if ($academicLevel !== null) {
            $match = $this->prices->findMatchForLevel($type, $subject, $academicLevel, $durationMinutes, $country, $instructorId);

            if ($match !== null) {
                return $match;
            }
        }

        return $this->prices->findMatchForAllLevels($type, $subject, $durationMinutes, $country, $instructorId);
    }
}
