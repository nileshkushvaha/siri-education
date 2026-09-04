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
use Illuminate\Support\Facades\Log;

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
 *
 * "Exact academic level" may be MORE THAN ONE level. Levels are
 * per-country ("Grade 10" for the US, "Class 10" for India, "Year 11"
 * for the UK) and a band level ("Secondary 6-10") can cover the same
 * grade as a single-grade one. Callers therefore pass an ORDERED list
 * of candidate levels — the one the student actually chose first, then
 * the rest that cover the grade — and each candidate is tried at tier
 * 1/3 before falling to the "all levels" row. Previously only ONE level
 * was tried (whichever `coversGrade()` hit first in table order), so a
 * price the admin had configured on the right level was invisible
 * whenever another level happened to sort ahead of it.
 */
final class StudentLessonPriceResolver
{
    public function __construct(
        private readonly StudentLessonPriceRepositoryInterface $prices,
    ) {}

    /**
     * Single-level entry point kept for callers that already hold ONE
     * level (marketplace quotes, package pricing).
     *
     * @throws BookingException when no active, currently-effective price matches
     */
    public function resolve(
        BookingType $type,
        Subject $subject,
        ?AcademicLevel $academicLevel,
        int $durationMinutes,
        Country $country,
        ?int $instructorId = null,
    ): StudentLessonPrice {
        return $this->resolveForLevels($type, $subject, $academicLevel === null ? [] : [$academicLevel], $durationMinutes, $country, $instructorId);
    }

    /**
     * @param  iterable<AcademicLevel>  $candidateLevels  ordered, most specific first; may be empty
     *
     * @throws BookingException when no active, currently-effective price matches
     */
    public function resolveForLevels(
        BookingType $type,
        Subject $subject,
        iterable $candidateLevels,
        int $durationMinutes,
        Country $country,
        ?int $instructorId = null,
    ): StudentLessonPrice {
        $levels = $this->uniqueLevels($candidateLevels);

        if ($instructorId !== null) {
            $match = $this->matchWithLevelFallback($type, $subject, $levels, $durationMinutes, $country, $instructorId);

            if ($match !== null) {
                return $match;
            }
        }

        $match = $this->matchWithLevelFallback($type, $subject, $levels, $durationMinutes, $country, null);

        if ($match !== null) {
            return $match;
        }

        // Students see the sentence; operators need the coordinates. Every
        // criterion the matrix row must satisfy is logged so support can
        // see at a glance WHICH of them the configured row misses.
        Log::warning('Student lesson price not configured for a paid booking.', [
            'booking_type_id' => $type->id,
            'booking_type_key' => $type->key,
            'subject_id' => $subject->id,
            'subject_slug' => $subject->slug,
            'country_id' => $country->id,
            'country_iso2' => $country->iso2,
            'duration_minutes' => $durationMinutes,
            'instructor_id' => $instructorId,
            'candidate_academic_level_ids' => array_map(fn (AcademicLevel $level): string => (string) $level->id, $levels),
            'candidate_academic_level_names' => array_map(fn (AcademicLevel $level): string => (string) $level->name, $levels),
        ]);

        throw new BookingException(sprintf(
            'The "%s" lesson price is not configured yet. Please contact support.',
            $type->name,
        ));
    }

    /**
     * Each candidate level in order, then "all levels" — within one
     * instructor scope (specific or base).
     *
     * @param  list<AcademicLevel>  $levels
     */
    private function matchWithLevelFallback(
        BookingType $type,
        Subject $subject,
        array $levels,
        int $durationMinutes,
        Country $country,
        ?int $instructorId,
    ): ?StudentLessonPrice {
        foreach ($levels as $level) {
            $match = $this->prices->findMatchForLevel($type, $subject, $level, $durationMinutes, $country, $instructorId);

            if ($match !== null) {
                return $match;
            }
        }

        return $this->prices->findMatchForAllLevels($type, $subject, $durationMinutes, $country, $instructorId);
    }

    /**
     * @param  iterable<AcademicLevel>  $levels
     * @return list<AcademicLevel>
     */
    private function uniqueLevels(iterable $levels): array
    {
        $seen = [];
        $unique = [];

        foreach ($levels as $level) {
            $key = (string) $level->id;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $level;
        }

        return $unique;
    }
}
