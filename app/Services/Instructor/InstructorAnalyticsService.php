<?php

declare(strict_types=1);

namespace App\Services\Instructor;

use App\DTOs\Instructor\InstructorAnalyticsData;
use App\DTOs\Instructor\InstructorPerformanceInsightsData;
use App\DTOs\Instructor\LessonTrendData;
use App\DTOs\Instructor\QualityTrendData;
use App\DTOs\Instructor\StudentEngagementData;
use App\Enums\InstructorAnalyticsPeriod;
use App\Homework\Contracts\HomeworkRepositoryInterface;
use App\Lessons\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\LessonReview;
use App\Models\User;
use App\Reporting\ValueObjects\ReportingPeriod;
use App\Reviews\Contracts\InstructorQualityInsightsServiceInterface;
use App\Reviews\Enums\LessonReviewEligibilityMode;
use App\Reviews\Enums\StudentReviewStatus;
use App\Reviews\Support\ReviewContributionEligibility;
use Carbon\CarbonImmutable;

/**
 * Instructor Analytics Foundation (Phase 23O) plus Advanced Performance
 * Insights (Phase 23P) — a thin read-only orchestrator across the
 * Lesson, Homework, and Reviews domains, plus the Phase 23N student-
 * relationship service. Every number is a bounded aggregate query;
 * nothing is materialized into PHP just to be counted (the one
 * deliberate exception is qualityTrends()'s small, bounded per-period
 * review set — see its own docblock), and nothing is written, cached,
 * or event-dispatched. Deliberately excludes earnings (Phase 23L
 * already owns instructor earnings visibility) and demo conversion
 * (existing attribution is platform-wide admin analytics only, not
 * instructor-scoped — "Conversion attribution requires an approved
 * business definition").
 */
final class InstructorAnalyticsService
{
    public function __construct(
        private readonly InstructorStudentService $students,
        private readonly HomeworkRepositoryInterface $homework,
        private readonly InstructorQualityInsightsServiceInterface $quality,
    ) {}

    public function overview(User $instructor, InstructorAnalyticsPeriod $period = InstructorAnalyticsPeriod::Last30Days): InstructorAnalyticsData
    {
        $reportingPeriod = $period->toReportingPeriod($instructor->profile?->timezone);
        $periodStart = $reportingPeriod?->startUtc;
        $periodEndExclusive = $reportingPeriod?->endUtcExclusive ?? CarbonImmutable::now()->utc()->addSecond();

        $instructorId = $instructor->id;

        return new InstructorAnalyticsData(
            period: $period,
            students: $this->studentsSummary($instructorId, $periodStart, $periodEndExclusive),
            lessons: $this->lessonsSummary($instructorId, $periodStart, $periodEndExclusive),
            quality: $this->qualitySummary($instructor),
            homework: $this->homeworkSummary($instructorId, $periodStart, $periodEndExclusive),
        );
    }

    /** @return array{total: int, active: int, new_this_period: int} */
    private function studentsSummary(int $instructorId, ?CarbonImmutable $periodStart, CarbonImmutable $periodEndExclusive): array
    {
        return [
            'total' => $this->students->totalCount($instructorId),
            'active' => $this->students->activeCount($instructorId, $periodStart, $periodEndExclusive),
            'new_this_period' => $this->students->newCount($instructorId, $periodStart, $periodEndExclusive),
        ];
    }

    /** @return array{total: int, completed: int, upcoming: int, cancelled: int, no_show: int} */
    private function lessonsSummary(int $instructorId, ?CarbonImmutable $periodStart, CarbonImmutable $periodEndExclusive): array
    {
        $historical = Lesson::query()
            ->forInstructor($instructorId)
            ->when($periodStart !== null, fn ($query) => $query->where('starts_at', '>=', $periodStart))
            ->where('starts_at', '<', $periodEndExclusive)
            ->selectRaw(
                'COUNT(*) as total,
                 SUM(status = ?) as completed,
                 SUM(status = ?) as cancelled,
                 SUM(status IN (?, ?, ?)) as no_show',
                [
                    LessonStatus::Completed->value,
                    LessonStatus::Cancelled->value,
                    LessonStatus::StudentNoShow->value, LessonStatus::InstructorNoShow->value, LessonStatus::BothNoShow->value,
                ],
            )
            ->first();

        // Always "what's actually next" regardless of the historical
        // period filter — matches the dashboard widget's own
        // upcoming-lesson semantics (Phase 23I/23K), never period-scoped.
        $upcoming = Lesson::query()
            ->forInstructor($instructorId)
            ->open()
            ->where('starts_at', '>=', now())
            ->count();

        return [
            'total' => (int) $historical->total,
            'completed' => (int) $historical->completed,
            'upcoming' => $upcoming,
            'cancelled' => (int) $historical->cancelled,
            'no_show' => (int) $historical->no_show,
        ];
    }

    /** @return array{average_rating: ?float, total_reviews: int} */
    private function qualitySummary(User $instructor): array
    {
        // The exact Phase 17K rating aggregate, reused unchanged —
        // never recalculated from review rows here.
        $ratingSummary = $this->quality->insightsFor($instructor)->ratingSummary;

        return [
            'average_rating' => $ratingSummary->averageRating,
            'total_reviews' => $ratingSummary->reviewCount,
        ];
    }

    /** @return array{assigned: int, submitted: int, graded: int} */
    private function homeworkSummary(int $instructorId, ?CarbonImmutable $periodStart, CarbonImmutable $periodEndExclusive): array
    {
        $stats = $this->homework->statsForTeacher($instructorId, $periodStart, $periodEndExclusive);

        return [
            'assigned' => (int) $stats->assigned,
            'submitted' => (int) $stats->submitted,
            'graded' => (int) $stats->graded,
        ];
    }

    // ── Phase 23P — Advanced Performance Insights ───────────────────────

    public function performanceInsights(User $instructor, InstructorAnalyticsPeriod $period = InstructorAnalyticsPeriod::Last30Days): InstructorPerformanceInsightsData
    {
        return new InstructorPerformanceInsightsData(
            period: $period,
            lessons: $this->lessonTrends($instructor, $period),
            quality: $this->qualityTrends($instructor, $period),
            students: $this->studentEngagement($instructor, $period),
        );
    }

    /** Current-vs-previous-period lesson counts — reuses the exact lessonsSummary() aggregate this class already computes for overview(), called twice. */
    public function lessonTrends(User $instructor, InstructorAnalyticsPeriod $period = InstructorAnalyticsPeriod::Last30Days): LessonTrendData
    {
        $reportingPeriod = $period->toReportingPeriod($instructor->profile?->timezone);
        $instructorId = $instructor->id;

        if ($reportingPeriod === null) {
            // AllTime has no meaningful "previous" window to compare against.
            $current = $this->lessonsSummary($instructorId, null, CarbonImmutable::now()->utc()->addSecond());

            return new LessonTrendData(
                completedCurrent: $current['completed'],
                completedPrevious: 0,
                completedChangePercent: null,
                cancelledCurrent: $current['cancelled'],
                cancelledPrevious: 0,
                noShowCurrent: $current['no_show'],
                noShowPrevious: 0,
                hasComparison: false,
            );
        }

        $current = $this->lessonsSummary($instructorId, $reportingPeriod->startUtc, $reportingPeriod->endUtcExclusive);
        [$previousStart, $previousEnd] = $this->previousPeriodBounds($reportingPeriod);
        $previous = $this->lessonsSummary($instructorId, $previousStart, $previousEnd);

        return new LessonTrendData(
            completedCurrent: $current['completed'],
            completedPrevious: $previous['completed'],
            completedChangePercent: $this->percentChange($current['completed'], $previous['completed']),
            cancelledCurrent: $current['cancelled'],
            cancelledPrevious: $previous['cancelled'],
            noShowCurrent: $current['no_show'],
            noShowPrevious: $previous['no_show'],
            hasComparison: true,
        );
    }

    /**
     * Current-vs-previous-period rating snapshot. InstructorRatingAggregate
     * has no period dimension (it is a single all-time running total), so a
     * period slice can only come from LessonReview rows directly — this
     * reuses ReviewContributionEligibility::qualifies(), the exact same
     * predicate the aggregate rebuild uses, so the two can never define
     * "eligible" differently. Review volume per instructor per period is
     * small and bounded (never lesson-scale), so loading the period's
     * review rows to apply this PHP-only predicate is safe and correct —
     * the alternative (reimplementing the predicate in SQL) risks exactly
     * the mismatch this design avoids.
     */
    public function qualityTrends(User $instructor, InstructorAnalyticsPeriod $period = InstructorAnalyticsPeriod::Last30Days): QualityTrendData
    {
        $reportingPeriod = $period->toReportingPeriod($instructor->profile?->timezone);

        if ($reportingPeriod === null) {
            $current = $this->ratingForPeriod($instructor->id, null, CarbonImmutable::now()->utc()->addSecond());

            return new QualityTrendData(
                averageRatingCurrent: $current['average'],
                averageRatingPrevious: null,
                reviewCountCurrent: $current['count'],
                reviewCountPrevious: 0,
                hasComparison: false,
            );
        }

        $current = $this->ratingForPeriod($instructor->id, $reportingPeriod->startUtc, $reportingPeriod->endUtcExclusive);
        [$previousStart, $previousEnd] = $this->previousPeriodBounds($reportingPeriod);
        $previous = $this->ratingForPeriod($instructor->id, $previousStart, $previousEnd);

        return new QualityTrendData(
            averageRatingCurrent: $current['average'],
            averageRatingPrevious: $previous['average'],
            reviewCountCurrent: $current['count'],
            reviewCountPrevious: $previous['count'],
            hasComparison: true,
        );
    }

    /** Active students (period-scoped, reusing InstructorStudentService) plus the point-in-time without-upcoming-lesson fact. */
    public function studentEngagement(User $instructor, InstructorAnalyticsPeriod $period = InstructorAnalyticsPeriod::Last30Days): StudentEngagementData
    {
        $reportingPeriod = $period->toReportingPeriod($instructor->profile?->timezone);
        $periodStart = $reportingPeriod?->startUtc;
        $periodEndExclusive = $reportingPeriod?->endUtcExclusive ?? CarbonImmutable::now()->utc()->addSecond();

        return new StudentEngagementData(
            activeStudents: $this->students->activeCount($instructor->id, $periodStart, $periodEndExclusive),
            studentsWithoutUpcomingLesson: $this->students->withoutUpcomingLessonCount($instructor->id),
        );
    }

    /** @return array{count: int, average: ?float} */
    private function ratingForPeriod(int $instructorId, ?CarbonImmutable $periodStart, CarbonImmutable $periodEndExclusive): array
    {
        $eligible = LessonReview::query()
            ->where('instructor_id', $instructorId)
            ->where('status', StudentReviewStatus::Published)
            ->where('review_mode', LessonReviewEligibilityMode::PublicReview)
            ->whereNotNull('overall_rating')
            ->when($periodStart !== null, fn ($query) => $query->where('submitted_at', '>=', $periodStart))
            ->where('submitted_at', '<', $periodEndExclusive)
            ->get(['id', 'status', 'review_mode', 'overall_rating', 'settings_snapshot', 'eligibility_id', 'lesson_id'])
            ->filter(fn (LessonReview $review): bool => ReviewContributionEligibility::qualifies($review));

        $count = $eligible->count();

        return [
            'count' => $count,
            'average' => $count > 0 ? round((float) $eligible->avg('overall_rating'), 1) : null,
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} [previousStartUtc, previousEndUtcExclusive) — the immediately-preceding window of the same duration as $current. */
    private function previousPeriodBounds(ReportingPeriod $current): array
    {
        $durationSeconds = $current->startUtc->diffInSeconds($current->endUtcExclusive);
        $previousEnd = $current->startUtc;
        $previousStart = $previousEnd->subSeconds($durationSeconds);

        return [$previousStart, $previousEnd];
    }

    private function percentChange(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
