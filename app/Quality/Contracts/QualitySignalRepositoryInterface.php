<?php

declare(strict_types=1);

namespace App\Quality\Contracts;

use Carbon\CarbonImmutable;
use Illuminate\Support\LazyCollection;

/**
 * Read-only queries against LessonReview/Lesson/Booking for
 * quality-signal purposes. The Quality domain is a consumer of these
 * tables (via their shared Eloquent models), never an owner — this
 * repository exists so counting/scanning logic doesn't leak into
 * detection Actions directly, without requiring changes to the
 * Reviews/Lessons/Booking domains' own repositories.
 */
interface QualitySignalRepositoryInterface
{
    /** Published public reviews for this instructor at or below the threshold, submitted within the window. */
    public function countLowPublishedReviews(int $instructorId, int $threshold, CarbonImmutable $since): int;

    /** Finalized InstructorNoShow lesson outcomes for this instructor within the window. */
    public function countInstructorNoShows(int $instructorId, CarbonImmutable $since): int;

    /** Host-attributed (instructor) booking cancellations for this instructor within the window. */
    public function countInstructorAttributedCancellations(int $instructorId, CarbonImmutable $since): int;

    /** Every published, low-rated public review submitted since $since — cursored, for reconciliation. */
    public function recentLowPublishedReviews(CarbonImmutable $since, int $threshold): LazyCollection;

    /** Every lesson finalized with an InstructorNoShow outcome since $since — cursored, for reconciliation. */
    public function recentInstructorNoShowLessons(CarbonImmutable $since): LazyCollection;
}
