<?php

declare(strict_types=1);

namespace App\Lessons\Events;

use App\Models\Lesson;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * LessonOutcomeService::finalize() can call cancel() from inside an
 * outer DB::transaction — ShouldDispatchAfterCommit defers dispatch
 * until that transaction actually commits, so the queued
 * ReverseEarningOnLessonCancelled listener never observes a lesson row
 * that isn't durably committed yet.
 */
final class LessonCancelled implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Lesson $lesson,
    ) {}
}
