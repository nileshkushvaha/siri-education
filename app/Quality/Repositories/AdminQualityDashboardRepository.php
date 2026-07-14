<?php

declare(strict_types=1);

namespace App\Quality\Repositories;

use App\Booking\Enums\BookingActor;
use App\Lessons\Enums\LessonOutcome;
use App\Models\Booking;
use App\Models\InstructorQualityAlert;
use App\Models\InstructorRatingAggregate;
use App\Models\Lesson;
use App\Models\LessonReview;
use App\Models\ReviewRatingContribution;
use App\Models\ReviewReport;
use App\Quality\Contracts\AdminQualityDashboardRepositoryInterface;
use App\Quality\DTOs\AlertQueueFilters;
use App\Quality\DTOs\ModerationQueueFilters;
use App\Quality\DTOs\ReportQueueFilters;
use App\Quality\DTOs\TrendDateRange;
use App\Quality\Enums\InstructorQualityAlertSeverity;
use App\Quality\Enums\InstructorQualityAlertStatus;
use App\Reviews\Enums\LessonReviewEligibilityMode;
use App\Reviews\Enums\ReviewReportStatus;
use App\Reviews\Enums\StudentReviewStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class AdminQualityDashboardRepository implements AdminQualityDashboardRepositoryInterface
{
    public function countReviewsByStatus(StudentReviewStatus $status): int
    {
        return LessonReview::query()->where('status', $status)->count();
    }

    public function countReportsByStatus(ReviewReportStatus $status): int
    {
        return ReviewReport::query()->where('status', $status)->count();
    }

    public function countAlertsByStatus(InstructorQualityAlertStatus $status): int
    {
        return InstructorQualityAlert::query()->where('status', $status)->count();
    }

    public function countActiveAlertsBySeverity(InstructorQualityAlertSeverity $severity): int
    {
        return InstructorQualityAlert::query()
            ->where('severity', $severity)
            ->whereIn('status', [InstructorQualityAlertStatus::Open, InstructorQualityAlertStatus::UnderReview])
            ->count();
    }

    public function instructorsWithPublishedRatingsCount(): int
    {
        return InstructorRatingAggregate::query()->where('eligible_review_count', '>', 0)->count();
    }

    public function platformEligiblePublishedReviewCount(): int
    {
        return (int) InstructorRatingAggregate::query()->sum('eligible_review_count');
    }

    public function platformAverageRating(): ?float
    {
        $totals = InstructorRatingAggregate::query()
            ->selectRaw('SUM(overall_rating_sum) as rating_sum, SUM(eligible_review_count) as review_count')
            ->first();

        if ($totals === null || (int) $totals->review_count === 0) {
            return null;
        }

        return round((int) $totals->rating_sum / (int) $totals->review_count, 2);
    }

    public function instructorNoShowCount(TrendDateRange $range): int
    {
        return Lesson::query()
            ->where('outcome', LessonOutcome::InstructorNoShow)
            ->whereBetween('outcome_finalized_at', [$range->start, $range->end])
            ->count();
    }

    public function instructorAttributedCancellationCount(TrendDateRange $range): int
    {
        return Booking::query()
            ->where('cancelled_by', BookingActor::Host)
            ->whereBetween('cancelled_at', [$range->start, $range->end])
            ->count();
    }

    public function moderationQueue(ModerationQueueFilters $filters, int $perPage): LengthAwarePaginator
    {
        return LessonReview::query()
            ->withCount('reports')
            ->with(['instructor:id,name', 'student:id,first_name,status', 'student.profile:id,user_id,student_status'])
            ->when($filters->status !== null, fn (Builder $q) => $q->where('status', $filters->status))
            ->when($filters->instructorId !== null, fn (Builder $q) => $q->where('instructor_id', $filters->instructorId))
            ->when($filters->rating !== null, fn (Builder $q) => $q->where('overall_rating', $filters->rating))
            ->when($filters->lessonType !== null, fn (Builder $q) => $q->whereHas(
                'eligibility',
                fn (Builder $eq) => $eq->where('lesson_type', $filters->lessonType),
            ))
            ->when($filters->submittedFrom !== null, fn (Builder $q) => $q->where('submitted_at', '>=', $filters->submittedFrom))
            ->when($filters->submittedUntil !== null, fn (Builder $q) => $q->where('submitted_at', '<=', $filters->submittedUntil))
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function reportQueue(ReportQueueFilters $filters, int $perPage): LengthAwarePaginator
    {
        return ReviewReport::query()
            ->with(['review' => fn ($q) => $q->withCount('reports')->with('instructor:id,name')])
            ->when($filters->status !== null, fn (Builder $q) => $q->where('status', $filters->status))
            ->when($filters->reason !== null, fn (Builder $q) => $q->where('reason', $filters->reason))
            ->when($filters->instructorId !== null, fn (Builder $q) => $q->whereHas(
                'review',
                fn (Builder $rq) => $rq->where('instructor_id', $filters->instructorId),
            ))
            ->when($filters->submittedFrom !== null, fn (Builder $q) => $q->where('submitted_at', '>=', $filters->submittedFrom))
            ->when($filters->submittedUntil !== null, fn (Builder $q) => $q->where('submitted_at', '<=', $filters->submittedUntil))
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function alertQueue(AlertQueueFilters $filters, int $perPage): LengthAwarePaginator
    {
        return InstructorQualityAlert::query()
            ->with(['instructor:id,name', 'assignee:id,name'])
            ->when($filters->type !== null, fn (Builder $q) => $q->where('alert_type', $filters->type))
            ->when($filters->severity !== null, fn (Builder $q) => $q->where('severity', $filters->severity))
            ->when($filters->status !== null, fn (Builder $q) => $q->where('status', $filters->status))
            ->when($filters->assignedTo !== null, fn (Builder $q) => $q->where('assigned_to', $filters->assignedTo))
            ->when($filters->instructorId !== null, fn (Builder $q) => $q->where('instructor_id', $filters->instructorId))
            ->when($filters->triggeredFrom !== null, fn (Builder $q) => $q->where('triggered_at', '>=', $filters->triggeredFrom))
            ->when($filters->triggeredUntil !== null, fn (Builder $q) => $q->where('triggered_at', '<=', $filters->triggeredUntil))
            ->orderByDesc('triggered_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function lowRatedInstructors(float $threshold, int $minReviewCount, int $limit = 25): Collection
    {
        return InstructorRatingAggregate::query()
            ->with('instructor:id,name')
            ->where('eligible_review_count', '>=', $minReviewCount)
            ->whereRaw('(overall_rating_sum * 1.0 / eligible_review_count) <= ?', [$threshold])
            ->orderByRaw('(overall_rating_sum * 1.0 / eligible_review_count) ASC')
            ->limit($limit)
            ->get();
    }

    public function highlyRatedInstructors(float $threshold, int $minReviewCount, int $limit = 25): Collection
    {
        return InstructorRatingAggregate::query()
            ->with('instructor:id,name')
            ->where('eligible_review_count', '>=', $minReviewCount)
            ->whereRaw('(overall_rating_sum * 1.0 / eligible_review_count) >= ?', [$threshold])
            ->orderByRaw('(overall_rating_sum * 1.0 / eligible_review_count) DESC')
            ->limit($limit)
            ->get();
    }

    public function publishedReviewsTrend(TrendDateRange $range): array
    {
        return $this->dailySeries(
            LessonReview::query()->where('status', StudentReviewStatus::Published),
            'submitted_at',
            $range,
        );
    }

    public function lowRatedPublishedReviewsTrend(TrendDateRange $range, int $threshold): array
    {
        return $this->dailySeries(
            LessonReview::query()
                ->where('status', StudentReviewStatus::Published)
                ->where('review_mode', LessonReviewEligibilityMode::PublicReview)
                ->where('overall_rating', '<=', $threshold),
            'submitted_at',
            $range,
        );
    }

    public function flaggedReviewsTrend(TrendDateRange $range): array
    {
        return $this->dailySeries(
            LessonReview::query()->where('status', StudentReviewStatus::Flagged),
            'submitted_at',
            $range,
        );
    }

    public function reviewReportsTrend(TrendDateRange $range): array
    {
        return $this->dailySeries(ReviewReport::query(), 'submitted_at', $range);
    }

    public function qualityAlertsTrend(TrendDateRange $range): array
    {
        return $this->dailySeries(InstructorQualityAlert::query(), 'triggered_at', $range);
    }

    public function instructorNoShowsTrend(TrendDateRange $range): array
    {
        return $this->dailySeries(
            Lesson::query()->where('outcome', LessonOutcome::InstructorNoShow),
            'outcome_finalized_at',
            $range,
        );
    }

    public function instructorAttributedCancellationsTrend(TrendDateRange $range): array
    {
        return $this->dailySeries(
            Booking::query()->where('cancelled_by', BookingActor::Host),
            'cancelled_at',
            $range,
        );
    }

    public function recentContributionRatings(int $instructorId, int $limit): array
    {
        return ReviewRatingContribution::query()
            ->where('instructor_id', $instructorId)
            ->where('included', true)
            ->orderByDesc('applied_at')
            ->limit($limit)
            ->pluck('overall_rating')
            ->map(fn ($rating) => (int) $rating)
            ->all();
    }

    /** @return array<string, int> */
    private function dailySeries(Builder $query, string $dateColumn, TrendDateRange $range): array
    {
        $counts = $query
            ->whereBetween($dateColumn, [$range->start, $range->end])
            ->selectRaw("DATE({$dateColumn}) as day, COUNT(*) as total")
            ->groupBy('day')
            ->pluck('total', 'day');

        $series = [];
        $cursor = $range->start;

        while ($cursor->lessThanOrEqualTo($range->end)) {
            $key = $cursor->format('Y-m-d');
            $series[$key] = (int) ($counts[$key] ?? 0);
            $cursor = $cursor->addDay();
        }

        return $series;
    }
}
