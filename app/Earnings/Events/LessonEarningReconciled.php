<?php

declare(strict_types=1);

namespace App\Earnings\Events;

use App\Models\InstructorEarning;
use App\Models\LessonFinancialDisposition;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An approved earning reconciliation executed: an earning was created,
 * held, released, or reversed for a finalized lesson outcome.
 * After-commit only; nothing currently listens for it.
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
