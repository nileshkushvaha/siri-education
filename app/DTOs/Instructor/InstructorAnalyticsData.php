<?php

declare(strict_types=1);

namespace App\DTOs\Instructor;

use App\Enums\InstructorAnalyticsPeriod;

/**
 * Instructor Analytics Foundation — every value is derived
 * from an existing authoritative domain (Lesson, HomeworkAssignment,
 * InstructorRatingAggregate via InstructorQualityInsightsService); none
 * of it is stored or recalculated here. Deliberately excludes earnings
 * (already fully covered by the dedicated Earnings/Settlements
 * pages) and demo conversion (attribution exists only as platform-wide
 * admin analytics, not instructor-scoped).
 */
final readonly class InstructorAnalyticsData
{
    /**
     * @param  array{total: int, active: int, new_this_period: int}  $students
     * @param  array{total: int, completed: int, upcoming: int, cancelled: int, no_show: int}  $lessons
     * @param  array{average_rating: ?float, total_reviews: int}  $quality
     * @param  array{assigned: int, submitted: int, graded: int}  $homework
     */
    public function __construct(
        public InstructorAnalyticsPeriod $period,
        public array $students,
        public array $lessons,
        public array $quality,
        public array $homework,
    ) {}
}
