<?php

declare(strict_types=1);

namespace App\Reviews\Services;

use App\Lessons\Enums\LessonOutcome;
use App\Models\Lesson;
use App\Models\LessonReviewEligibility;
use App\Reviews\Actions\ExpireLessonReviewEligibilityAction;
use App\Reviews\Actions\OpenLessonReviewEligibilityAction;
use App\Reviews\Actions\ReevaluateLessonReviewEligibilityAction;
use App\Reviews\Contracts\LessonReviewEligibilityRepositoryInterface;
use App\Reviews\Contracts\ReviewEligibilityServiceInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates Phase 17H: opening eligibility on completion, reacting
 * to outcome corrections, and the batched expiration sweep. All
 * per-record logic lives in the three actions — this class only routes
 * and batches.
 */
final class ReviewEligibilityService implements ReviewEligibilityServiceInterface
{
    private const int BATCH_SIZE = 100;

    public function __construct(
        private readonly LessonReviewEligibilityRepositoryInterface $eligibilities,
        private readonly OpenLessonReviewEligibilityAction $open,
        private readonly ReevaluateLessonReviewEligibilityAction $reevaluate,
        private readonly ExpireLessonReviewEligibilityAction $expire,
    ) {}

    public function handleOutcomeFinalized(Lesson $lesson, LessonOutcome $outcome): ?LessonReviewEligibility
    {
        if ($outcome !== LessonOutcome::Completed) {
            return null; // no standard eligibility for any non-completed outcome
        }

        return $this->open->execute($lesson);
    }

    public function handleOutcomeOverridden(Lesson $lesson, LessonOutcome $previousOutcome, LessonOutcome $newOutcome, string $overrideReason): ?LessonReviewEligibility
    {
        return $this->reevaluate->execute($lesson, $previousOutcome, $newOutcome, $overrideReason);
    }

    public function expireDue(): int
    {
        $expired = 0;

        foreach ($this->eligibilities->dueForExpiration(now(), self::BATCH_SIZE) as $eligibility) {
            try {
                $expired += $this->expire->execute($eligibility) ? 1 : 0;
            } catch (Throwable $e) {
                // One failure never stops the sweep; the row stays Open
                // and is picked up again next run.
                Log::warning('reviews:expire-eligibility — record skipped after failure', [
                    'eligibility_id' => $eligibility->id,
                    'lesson_id' => $eligibility->lesson_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $expired;
    }
}
