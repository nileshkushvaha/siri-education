<?php

declare(strict_types=1);

namespace App\DTOs\Instructor;

use App\Enums\InstructorEligibilityCode;

final readonly class InstructorEligibilityResult
{
    public function __construct(
        public bool $eligible,
        public InstructorEligibilityCode $code,
        public ?string $reason,
    ) {}

    public static function eligible(): self
    {
        return new self(eligible: true, code: InstructorEligibilityCode::Eligible, reason: null);
    }

    public static function ineligible(InstructorEligibilityCode $code, string $reason): self
    {
        return new self(eligible: false, code: $code, reason: $reason);
    }
}
