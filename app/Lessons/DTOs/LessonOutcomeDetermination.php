<?php

declare(strict_types=1);

namespace App\Lessons\DTOs;

use App\Lessons\Enums\LessonOutcome;

/** Result of DetermineLessonOutcomeAction — pure evaluation, no writes. */
final readonly class LessonOutcomeDetermination
{
    public function __construct(
        public LessonOutcome $outcome,
        public string $reasonCode,
        public bool $studentQualifies,
        public bool $instructorQualifies,
    ) {}
}
