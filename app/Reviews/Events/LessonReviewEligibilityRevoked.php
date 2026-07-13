<?php

declare(strict_types=1);

namespace App\Reviews\Events;

use App\Models\LessonReviewEligibility;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** An unused eligibility window was revoked (outcome corrected away from Completed). After-commit only. */
final class LessonReviewEligibilityRevoked implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly LessonReviewEligibility $eligibility,
        public readonly string $reason,
    ) {}
}
