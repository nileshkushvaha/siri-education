<?php

declare(strict_types=1);

namespace App\Reviews\Events;

use App\Models\LessonReviewEligibility;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** An open eligibility window's review deadline passed unused. After-commit only. */
final class LessonReviewEligibilityExpired implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly LessonReviewEligibility $eligibility,
    ) {}
}
