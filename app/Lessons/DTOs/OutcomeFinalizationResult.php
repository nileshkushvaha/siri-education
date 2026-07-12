<?php

declare(strict_types=1);

namespace App\Lessons\DTOs;

use App\Lessons\Enums\LessonOutcome;
use App\Models\Lesson;

/** Result of an outcome finalization/override attempt — applied=false means idempotent repeat, not failure. */
final readonly class OutcomeFinalizationResult
{
    public function __construct(
        public Lesson $lesson,
        public bool $applied,
        public LessonOutcome $previousOutcome,
    ) {}
}
