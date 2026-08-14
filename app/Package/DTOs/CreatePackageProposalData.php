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
        /**
         * Legacy path only. Once the country-aware packages feature is
         * in effect for the student's country, the AcademicLevel is
         * DERIVED from $educationSystemLevelId (a student never picks
         * the broad band directly) and anything supplied here is
         * ignored — the derived value always wins, so a forged id
         * cannot widen or shift the package's academic identity.
         */
        public ?string $academicLevelId,
        /**
         * Phase 4D — the instructor's structured selection. Both are
         * raw, UNTRUSTED client ids: they are re-resolved and validated
         * server-side (country served from CountryResolver, never from
         * the browser) before anything is persisted, and re-resolved a
         * second time at submit before the snapshot is frozen.
         */
        public ?string $educationSystemId = null,
        public ?string $educationSystemLevelId = null,
    ) {}
}
