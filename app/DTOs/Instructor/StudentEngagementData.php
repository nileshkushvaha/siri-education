<?php

declare(strict_types=1);

namespace App\DTOs\Instructor;

/**
 * Student engagement indicators — deliberately limited to
 * two SRS-safe facts. No "at risk" scoring, no invented retention
 * definition: a student either had a lesson in the selected period, or
 * has no lesson currently scheduled in the future. Never carries a
 * student name/id — aggregate counts only.
 */
final readonly class StudentEngagementData
{
    public function __construct(
        public int $activeStudents,
        public int $studentsWithoutUpcomingLesson,
    ) {}
}
