<?php

declare(strict_types=1);

namespace App\Earnings\Events;

use App\Models\InstructorEarning;
use App\Models\LessonFinancialDisposition;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An approved earning reconciliation executed (Phase 17G): an earning
 * was created, held, released, or reversed for a finalized lesson
 * outcome. After-commit only; no listeners are attached in this phase.
 */
final class LessonEarningReconciled implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly LessonFinancialDisposition $disposition,
        public readonly ?InstructorEarning $earning,
        public readonly string $action,
    ) {}
}
