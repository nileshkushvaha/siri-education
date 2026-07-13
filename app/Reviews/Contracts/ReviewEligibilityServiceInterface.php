<?php

declare(strict_types=1);

namespace App\Reviews\Contracts;

use App\Lessons\Enums\LessonOutcome;
use App\Models\Lesson;
use App\Models\LessonReviewEligibility;

/**
 * Single entry point for review-eligibility decisions. Listeners for
 * LessonOutcomeFinalized/LessonOutcomeOverridden delegate here — no
 * eligibility logic lives in the listeners themselves.
 */
interface ReviewEligibilityServiceInterface
{
    /**
     * A lesson outcome finalized. Opens eligibility when the outcome is
     * Completed and policy allows it; every other outcome is a no-op
     * (idempotent — a duplicate/replayed event never creates a second
     * record).
     */
    public function handleOutcomeFinalized(Lesson $lesson, LessonOutcome $outcome): ?LessonReviewEligibility;

    /**
     * A finalized outcome was corrected. Revokes an unused open window
     * when correcting away from Completed, flags a used window for
     * manual review, or opens/restores eligibility when correcting
     * TO Completed. History is preserved; nothing is ever deleted.
     */
    public function handleOutcomeOverridden(Lesson $lesson, LessonOutcome $previousOutcome, LessonOutcome $newOutcome, string $overrideReason): ?LessonReviewEligibility;

    /**
     * Expire every overdue Open eligibility in deterministic batches.
     * Idempotent and safe under concurrent runs. Returns the number
     * expired this call.
     */
    public function expireDue(): int;
}
