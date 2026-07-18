<?php

declare(strict_types=1);

namespace App\Services\Instructor;

use App\DTOs\Instructor\InstructorAnalyticsData;
use App\Enums\InstructorAnalyticsPeriod;
use App\Homework\Contracts\HomeworkRepositoryInterface;
use App\Lessons\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\User;
use App\Reviews\Contracts\InstructorQualityInsightsServiceInterface;
use Carbon\CarbonImmutable;

/**
 * Instructor Analytics Foundation (Phase 23O) — a thin read-only
 * orchestrator across the Lesson, Homework, and Reviews domains, plus
 * the Phase 23N student-relationship service. Every number is a
 * bounded aggregate query; nothing is materialized into PHP just to
 * be counted, and nothing is written, cached, or event-dispatched.
 * Deliberately excludes earnings (Phase 23L already owns instructor
 * earnings visibility) and demo conversion (existing attribution is
 * platform-wide admin analytics only, not instructor-scoped — see the
 * Phase 23O final report for the deferral rationale).
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
}
