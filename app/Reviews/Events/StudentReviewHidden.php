<?php

declare(strict_types=1);

namespace App\Reviews\Events;

use App\Models\LessonReview;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** An admin hid a previously published review. After-commit only. */
final class StudentReviewHidden implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly LessonReview $review,
        public readonly string $reason,
    ) {}
}
