<?php

declare(strict_types=1);

namespace App\Reviews\Actions;

use App\Lessons\Enums\LessonOutcome;
use App\Models\Lesson;
use App\Models\LessonReviewEligibility;
use App\Reviews\Contracts\LessonReviewEligibilityRepositoryInterface;
use App\Reviews\Enums\LessonReviewEligibilityStatus;
use App\Reviews\Events\LessonReviewEligibilityRevoked;
use App\Services\AuditTrailService;
use Illuminate\Support\Facades\DB;

/**
 * Handles an outcome correction (LessonOutcomeOverridden). Never
 * deletes a row, never creates a second row for the same
 * (lesson, student) — every correction transitions the existing record
 * (or, for a lesson that was never eligible, does nothing at all).
 */
final class ReevaluateLessonReviewEligibilityAction
{
    private const string LOG_NAME = 'reviews';

    public function __construct(
        private readonly LessonReviewEligibilityRepositoryInterface $eligibilities,
        private readonly OpenLessonReviewEligibilityAction $open,
        private readonly AuditTrailService $audit,
    ) {}

    public function execute(Lesson $lesson, LessonOutcome $previousOutcome, LessonOutcome $newOutcome, string $overrideReason): ?LessonReviewEligibility
    {
        return DB::transaction(function () use ($lesson, $newOutcome, $overrideReason): ?LessonReviewEligibility {
            // Non-completed → completed: open fresh, or restore a
            // previously revoked window. The open action is itself
            // idempotent and policy-gated.
            if ($newOutcome === LessonOutcome::Completed) {
                return $this->open->execute($lesson);
            }

            $eligibility = $this->eligibilities->findForLessonAndStudent($lesson, $lesson->student_id);

            if ($eligibility === null) {
                return null; // never eligible — nothing to correct
            }

            $eligibility = $this->eligibilities->lock($eligibility);

            return match ($eligibility->status) {
                LessonReviewEligibilityStatus::Open => $this->revoke($eligibility, $lesson, $overrideReason),
                LessonReviewEligibilityStatus::Used => $this->flagManualReview($eligibility, $lesson),
                // Expired / Revoked / ManualReview already reflect a
                // non-completed state or a settled human decision —
                // idempotent no-op, never regressed further.
                default => $eligibility,
            };
        });
    }

    /** Completed → non-completed, unused window: revoke it. Requires a reason (the override's own reason). */
    private function revoke(LessonReviewEligibility $eligibility, Lesson $lesson, string $reason): LessonReviewEligibility
    {
        $eligibility->history = [...($eligibility->history ?? []), $eligibility->snapshotForHistory()];
        $eligibility->fill([
            'status' => LessonReviewEligibilityStatus::Revoked,
            'revoked_at' => now()->toImmutable()->utc(),
            'revoked_reason' => $reason,
            'version' => $eligibility->version + 1,
        ])->save();

        $this->audit->logSystem(
            self::LOG_NAME,
            'review_eligibility_revoked',
            sprintf('Review eligibility revoked for lesson %s: %s', $lesson->id, $reason),
            $eligibility,
            ['lesson_id' => $lesson->id, 'reason' => $reason],
        );

        LessonReviewEligibilityRevoked::dispatch($eligibility, $reason);

        return $eligibility;
    }

    /**
     * Completed → non-completed, but the eligibility was already Used —
     * a review may already exist. Never silently hide or delete it;
     * park the record for a human decision instead.
     */
    private function flagManualReview(LessonReviewEligibility $eligibility, Lesson $lesson): LessonReviewEligibility
    {
        $eligibility->history = [...($eligibility->history ?? []), $eligibility->snapshotForHistory()];
        $eligibility->fill([
            'status' => LessonReviewEligibilityStatus::ManualReview,
            'version' => $eligibility->version + 1,
        ])->save();

        $this->audit->logSystem(
            self::LOG_NAME,
            'review_eligibility_flagged_manual_review',
            sprintf('Lesson %s outcome corrected after its review eligibility was used — flagged for manual review.', $lesson->id),
            $eligibility,
            ['lesson_id' => $lesson->id],
        );

        return $eligibility;
    }
}
