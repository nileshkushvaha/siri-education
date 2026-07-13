<?php

declare(strict_types=1);

namespace App\Reviews\Events;

use App\Models\ReviewReport;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** An admin dismissed a report as invalid. After-commit only. No listener attached in Phase 17M. */
final class ReviewReportDismissed implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly ReviewReport $report,
    ) {}
}
