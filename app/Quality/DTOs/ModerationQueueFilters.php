<?php

declare(strict_types=1);

namespace App\Quality\DTOs;

use App\Reviews\Enums\ReviewableLessonType;
use App\Reviews\Enums\StudentReviewStatus;
use Carbon\CarbonImmutable;

final readonly class ModerationQueueFilters
{
    public function __construct(
        public ?StudentReviewStatus $status = null,
        public ?int $instructorId = null,
        public ?int $rating = null,
        public ?ReviewableLessonType $lessonType = null,
        public ?CarbonImmutable $submittedFrom = null,
        public ?CarbonImmutable $submittedUntil = null,
    ) {}
}
