<?php

declare(strict_types=1);

namespace App\Reviews\Events;

use App\Models\LessonReview;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A student edited their own review within the allowed window
 * (Phase 17R). After-commit only. The only listeners attached are the
 * existing moderation pipeline (re-moderate a now-Submitted edit) and
 * the existing rating-contribution reconciler — no notification,
 * instructor-response, quality-scoring, ranking, Learning Plan, or AI
 * listener exists in this phase.
 */
final class StudentReviewEdited implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly LessonReview $review,
    ) {}
}
