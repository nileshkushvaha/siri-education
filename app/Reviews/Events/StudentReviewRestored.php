<?php

declare(strict_types=1);

namespace App\Reviews\Events;

use App\Models\LessonReview;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** An admin restored a hidden review back to Published. After-commit only. */
final class StudentReviewRestored implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly LessonReview $review,
        public readonly string $reason,
    ) {}
}
