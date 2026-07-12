<?php

declare(strict_types=1);

namespace App\Lessons\Events;

use App\Lessons\Enums\LessonOutcome;
use App\Models\Lesson;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** An administrator corrected a finalized outcome (permission + reason + audit). After-commit only. */
final class LessonOutcomeOverridden implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Lesson $lesson,
        public readonly LessonOutcome $previousOutcome,
        public readonly LessonOutcome $outcome,
        public readonly string $reason,
    ) {}
}
