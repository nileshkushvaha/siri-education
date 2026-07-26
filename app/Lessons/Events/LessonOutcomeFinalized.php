<?php

declare(strict_types=1);

namespace App\Lessons\Events;

use App\Lessons\Enums\LessonOutcome;
use App\Models\Lesson;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Emitted exactly once per lesson, the moment its outcome moves from
 * Pending to a finalized value — FinalizeLessonOutcomeAction dispatches
 * it inside the finalizing transaction (released only on commit), so
 * concurrent finalizers and repeated commands can never double-fire it.
 * Listened to by ClassifyLessonFinancialDisposition,
 * OpenReviewEligibilityOnLessonOutcomeFinalized,
 * DetectInstructorNoShowQualityRiskOnLessonOutcomeFinalized,
 * EvaluateReferralRewardOnLessonOutcomeFinalized, and
 * RecalculateLearningPlanProgressOnLessonOutcomeFinalized.
 */
final class LessonOutcomeFinalized implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Lesson $lesson,
        public readonly LessonOutcome $outcome,
        public readonly string $reasonCode,
    ) {}
}
