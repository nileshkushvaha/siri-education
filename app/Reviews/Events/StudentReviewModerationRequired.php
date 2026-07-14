<?php

declare(strict_types=1);

namespace App\Reviews\Events;

use App\Models\LessonReview;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A review's status just became final in a state that genuinely
 * requires human moderation — Flagged (from initial submission or an
 * edit), or Submitted with automatic publication declined
 * (pre-moderation, or auto-publish disabled). Dispatched exactly once,
 * only from the single authoritative point that decided each of those
 * outcomes (SubmitLessonReviewAction, EditStudentReviewAction, and
 * ModerateSubmittedReviewAction's "held for manual moderation"
 * branch) — never derived by a listener re-deciding the outcome from
 * an earlier event, which would depend on fragile listener ordering
 * against the automatic-moderation listener that may still move the
 * review to Published before such a listener ran. After-commit only.
 */
final class StudentReviewModerationRequired implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly LessonReview $review,
    ) {}
}
