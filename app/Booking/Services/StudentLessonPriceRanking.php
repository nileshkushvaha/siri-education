<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Models\StudentLessonPrice;
use Illuminate\Support\Collection;

/**
 * The single pure ranking rule shared by StudentLessonPriceResolver's
 * transactional (checkout-time) matching and MarketplaceLessonPriceService's
 * read-only batch preview, so the two paths can never disagree about
 * which row wins.
 *
 * Given every ACTIVE, EFFECTIVE-NOW row already filtered to one exact
 * (booking type, subject, country, duration) key — mixing instructor-
 * specific and base rows, exact-level and all-level rows — picks the
 * same winner StudentLessonPriceResolver's four-tier lookup would:
 *
 *   1. instructor-specific + exact academic level
 *   2. instructor-specific + null academic level ("all levels")
 *   3. base price (no instructor) + exact academic level
 *   4. base price (no instructor) + null academic level ("all levels")
 *
 * and, within whichever tier has a match, the same tie-break the
 * repository's own query uses: priority DESC, effective_from DESC,
 * created_at DESC.
 */
final class StudentLessonPriceRanking
{
    /** @param  Collection<int, StudentLessonPrice>  $candidates */
    public static function winner(Collection $candidates, ?int $instructorId, ?string $academicLevelId): ?StudentLessonPrice
    {
        foreach (self::tiers($instructorId, $academicLevelId) as $tier) {
            $matches = $candidates->filter($tier);

            if ($matches->isNotEmpty()) {
                return self::highestPriority($matches);
            }
        }

        return null;
    }

    /** @return list<callable(StudentLessonPrice): bool> */
    private static function tiers(?int $instructorId, ?string $academicLevelId): array
    {
        $tiers = [];

        if ($instructorId !== null) {
            if ($academicLevelId !== null) {
                $tiers[] = fn (StudentLessonPrice $p): bool => $p->instructor_id === $instructorId && $p->academic_level_id === $academicLevelId;
            }
            $tiers[] = fn (StudentLessonPrice $p): bool => $p->instructor_id === $instructorId && $p->academic_level_id === null;
        }

        if ($academicLevelId !== null) {
            $tiers[] = fn (StudentLessonPrice $p): bool => $p->instructor_id === null && $p->academic_level_id === $academicLevelId;
        }

        $tiers[] = fn (StudentLessonPrice $p): bool => $p->instructor_id === null && $p->academic_level_id === null;

        return $tiers;
    }

    /** @param  Collection<int, StudentLessonPrice>  $matches */
    private static function highestPriority(Collection $matches): StudentLessonPrice
    {
        $sorted = $matches->all();

        usort($sorted, static fn (StudentLessonPrice $a, StudentLessonPrice $b): int => [
            $b->priority,
            $b->effective_from?->timestamp ?? 0,
            $b->created_at?->timestamp ?? 0,
        ] <=> [
            $a->priority,
            $a->effective_from?->timestamp ?? 0,
            $a->created_at?->timestamp ?? 0,
        ]);

        return $sorted[0];
    }
}
