<?php

declare(strict_types=1);

namespace App\Package\DTOs;

/**
 * Instructor-submitted input for InstructorPackageProposalService::create().
 * Deliberately carries no price field of any kind — price is always
 * server-resolved from PackagePricingService, never accepted from the
 * client (an instructor "must not set package price, override price,
 * or bypass admin approval"). Also carries no duration field — the
 * service always uses the paid lesson type's own configured
 * duration_minutes (the same value StudentLessonPrice rows are keyed
 * on), never an instructor-chosen one.
 */
final readonly class CreatePackageProposalData
{
    public function __construct(
        public int $instructorId,
        public int $studentId,
        public string $packageBenefitRuleId,
        public string $subjectId,
        public ?string $academicLevelId,
    ) {}
}
