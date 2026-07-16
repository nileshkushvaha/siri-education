<?php

declare(strict_types=1);

namespace App\Lessons\Events;

use App\Models\Lesson;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired by LessonLifecycleService after every completion (manual, admin,
 * and auto). LessonOutcomeService::finalize() can call complete() from
 * inside an outer DB::transaction (e.g. manual review confirmation, the
 * automated finalizer) — ShouldDispatchAfterCommit defers dispatch until
 * that outer transaction actually commits, so queued listeners (e.g.
 * CreateEarningOnLessonCompleted) never observe a lesson row that isn't
 * durably committed yet.
 */
final class LessonCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Lesson $lesson,
    ) {}
}
