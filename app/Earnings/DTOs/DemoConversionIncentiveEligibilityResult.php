<?php

declare(strict_types=1);

namespace App\Earnings\DTOs;

use App\Models\Lesson;

/**
 * Every branch of DemoConversionIncentiveEligibilityResolver returns a
 * stable, machine-readable reason code (never a boolean alone),
 * matching this codebase's established eligibility-DTO convention
 * (e.g. RecordingEligibilityResult).
 */
final readonly class DemoConversionIncentiveEligibilityResult
{
    private function __construct(
        public bool $eligible,
        public ?string $reason,
        public ?Lesson $demoLesson,
    ) {}

    public static function eligible(Lesson $demoLesson): self
    {
        return new self(true, null, $demoLesson);
    }

    public static function ineligible(string $reason): self
    {
        return new self(false, $reason, null);
    }
}
