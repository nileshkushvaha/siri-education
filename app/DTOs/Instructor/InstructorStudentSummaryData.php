<?php

declare(strict_types=1);

namespace App\DTOs\Instructor;

use App\Enums\LearningPlanStatus;
use Carbon\CarbonImmutable;

/** One student's teaching-relationship summary with a single instructor, derived from Lesson — never student PII beyond a display name/avatar. */
final readonly class InstructorStudentSummaryData
{
    public function __construct(
        public int $studentId,
        public string $studentSlug,
        public string $name,
        public ?string $avatarUrl,
        public int $lessonsCount,
        public int $completedLessons,
        public int $upcomingLessonsCount,
        public ?CarbonImmutable $lastLessonAt,
        public ?CarbonImmutable $nextLessonAt,
        public ?LearningPlanStatus $learningPlanStatus,
    ) {}
}
