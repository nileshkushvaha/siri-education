<?php

declare(strict_types=1);

namespace App\Reviews\Repositories;

use App\Models\LessonReview;
use App\Models\ReviewReport;
use App\Models\User;
use App\Reviews\Contracts\ReviewReportRepositoryInterface;
use App\Reviews\Enums\ReviewReportReason;
use App\Reviews\Enums\ReviewReportStatus;
use Illuminate\Support\Collection;

final class ReviewReportRepository implements ReviewReportRepositoryInterface
{
    public function create(array $attributes): ReviewReport
    {
        return ReviewReport::query()->create($attributes);
    }

    public function lock(ReviewReport $report): ReviewReport
    {
        return ReviewReport::query()
            ->whereKey($report->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function findActiveForReporter(LessonReview $review, User $reporter, ReviewReportReason $reason): ?ReviewReport
    {
        return ReviewReport::query()
            ->where('review_id', $review->id)
            ->where('reporter_id', $reporter->id)
            ->where('reason', $reason)
            ->whereIn('status', [ReviewReportStatus::Pending, ReviewReportStatus::UnderReview])
            ->lockForUpdate()
            ->first();
    }

    public function pendingOrUnderReviewForReview(LessonReview $review, ?string $excludeReportId = null): Collection
    {
        return ReviewReport::query()
            ->where('review_id', $review->id)
            ->whereIn('status', [ReviewReportStatus::Pending, ReviewReportStatus::UnderReview])
            ->when($excludeReportId !== null, fn ($query) => $query->whereKeyNot($excludeReportId))
            ->lockForUpdate()
            ->get();
    }
}
