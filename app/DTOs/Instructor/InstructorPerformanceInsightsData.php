<?php

declare(strict_types=1);

namespace App\DTOs\Instructor;

use App\Enums\InstructorAnalyticsPeriod;

/**
 * Advanced instructor performance insights — trend layer
 * on top of InstructorAnalyticsData's snapshot. Deliberately
 * excludes demo conversion: existing attribution is platform-wide
 * admin analytics only, not instructor-scoped — "Conversion attribution
 * requires an approved business definition."
 */
final readonly class InstructorPerformanceInsightsData
{
    public function __construct(
        public InstructorAnalyticsPeriod $period,
        public LessonTrendData $lessons,
        public QualityTrendData $quality,
        public StudentEngagementData $students,
    ) {}
}
