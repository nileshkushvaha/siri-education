<?php

declare(strict_types=1);

namespace App\Lessons\Events;

use App\Models\Lesson;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Not currently dispatched from inside an outer transaction (its only
 * caller today is the outermost Filament admin action), but every
 * sibling lifecycle event in this domain (LessonCompleted,
 * LessonCancelled, LessonOutcomeFinalized, LessonOutcomeOverridden)
 * implements ShouldDispatchAfterCommit — kept consistent so this stays
 * safe if a future caller ever nests dispute() inside a transaction.
 */
final class LessonDisputed implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Lesson $lesson,
    ) {}
}
