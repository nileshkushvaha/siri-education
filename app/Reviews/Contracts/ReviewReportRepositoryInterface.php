<?php

declare(strict_types=1);

namespace App\Reviews\Contracts;

use App\Models\LessonReview;
use App\Models\ReviewReport;
use App\Models\User;
use App\Reviews\Enums\ReviewReportReason;
use Illuminate\Support\Collection;

interface ReviewReportRepositoryInterface
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): ReviewReport;

    /** Refetch with a row lock — call only inside a transaction. */
    public function lock(ReviewReport $report): ReviewReport;

    /** The reporter's own active (Pending/UnderReview) report against this review for this reason, if any. */
    public function findActiveForReporter(LessonReview $review, User $reporter, ReviewReportReason $reason): ?ReviewReport;

    /** Every Pending/UnderReview report for this review, optionally excluding one already-resolved report. */
    public function pendingOrUnderReviewForReview(LessonReview $review, ?string $excludeReportId = null): Collection;
}
