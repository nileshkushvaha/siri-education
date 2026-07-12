<?php

declare(strict_types=1);

namespace App\Lessons\DTOs;

use App\Enums\ActivityActorType;
use App\Lessons\Enums\LessonOutcome;
use App\Models\User;

/** Context for finalizing a lesson outcome — who, why, and which rules may be relaxed. */
final readonly class OutcomeFinalizationData
{
    public function __construct(
        public LessonOutcome $outcome,
        public string $reasonCode,
        public ActivityActorType $byType,
        public ?User $actor = null,
        public ?string $notes = null,
        /**
         * Set only by the existing admin/booking-driven override-completion
         * paths (LessonLifecycleService::complete(override: true)) — relaxes
         * the "never complete before the scheduled end" rule for an
         * explicitly authorized early completion.
         */
        public bool $allowEarlyCompletion = false,
    ) {}
}
