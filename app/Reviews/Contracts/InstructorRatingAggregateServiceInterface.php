<?php

declare(strict_types=1);

namespace App\Reviews\Contracts;

use App\Models\InstructorRatingAggregate;
use App\Models\LessonReview;
use App\Reviews\DTOs\InstructorRatingSummaryData;

/**
 * Single entry point for rating-aggregate maintenance and reads.
 * Every review-lifecycle listener delegates here — no aggregate
 * mutation happens anywhere else, including inside moderation
 * services themselves.
 */
interface InstructorRatingAggregateServiceInterface
{
    /**
     * Reconcile one review's contribution to its instructor's
     * aggregate. Idempotent and safe to call for any lifecycle event,
     * any number of times, in any order.
     */
    public function reconcile(LessonReview $review): void;

    /**
     * Read-only summary for public/dashboard use. Returns a
     * zero-review summary (null average, empty distribution) for an
     * instructor with no aggregate row yet — never null.
     */
    public function summaryFor(int $instructorId): InstructorRatingSummaryData;

    /**
     * Repair/reconciliation tool, not the primary update path: fully
     * recomputes one instructor's aggregate (and its contribution
     * ledger) from current ground truth. Idempotent.
     */
    public function rebuildForInstructor(int $instructorId): InstructorRatingAggregate;

    /**
     * Rebuild every instructor with at least one review, in
     * deterministic order, isolating failures per instructor. Returns
     * the number of instructors successfully rebuilt.
     */
    public function rebuildAll(): int;
}
