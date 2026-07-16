<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Lessons\Events\LessonCancelled;
use App\Lessons\Events\LessonCompleted;
use App\Lessons\Events\LessonDisputed;
use App\Models\Lesson;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Tests\TestCase;

/**
 * Phase 17 closure audit — LessonOutcomeService::finalize() can call
 * LessonLifecycleService::complete()/cancel() from inside its own outer
 * DB::transaction (manual review confirmation, the automated
 * finalizer). Without ShouldDispatchAfterCommit, LessonCompleted/
 * LessonCancelled fired mid-transaction, so a queued listener
 * (CreateEarningOnLessonCompleted / ReverseEarningOnLessonCancelled)
 * could observe a lesson row that wasn't durably committed yet — or
 * never would be, if a later step in the same outer transaction rolled
 * back. LessonDisputed is not currently reachable from inside a nested
 * transaction, but is hardened identically for consistency with every
 * sibling lifecycle event in this domain (LessonOutcomeFinalized,
 * LessonOutcomeOverridden already had this).
 */
class LessonEventTransactionHardeningTest extends TestCase
{
    public function test_lesson_completed_dispatches_after_commit(): void
    {
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, new LessonCompleted(new Lesson));
    }

    public function test_lesson_cancelled_dispatches_after_commit(): void
    {
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, new LessonCancelled(new Lesson));
    }

    public function test_lesson_disputed_dispatches_after_commit(): void
    {
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, new LessonDisputed(new Lesson));
    }
}
