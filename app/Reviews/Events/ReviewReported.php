<?php

declare(strict_types=1);

namespace App\Reviews\Events;

use App\Models\ReviewReport;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A user reported a published public review. After-commit only. Listened to by SendReviewReportedNotification. */
final class ReviewReported implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly ReviewReport $report,
    ) {}
}
