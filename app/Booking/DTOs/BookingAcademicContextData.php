<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use App\Curriculum\DTOs\AcademicContextData;

/**
 * Booking-domain snapshot DTO — the typed carrier for a fully-resolved,
 * already-validated academic context traveling from
 * DemoAcademicContextResolver through WizardBookingService/
 * CreateBookingData into the persisted BookingAcademicContext row.
 *
 * Deliberately distinct from App\Curriculum\DTOs\AcademicContextData:
 * that DTO is the Curriculum domain's own pure id-only carrier and must
 * not be coupled to persistence/display concerns. This DTO adds the
 * denormalized historical display values the snapshot table requires
 * (§20/§23 of the Phase 3 spec) without pulling Booking-specific
 * concerns into the Curriculum domain.
 *
 * Phase 3.1: educationSystemLevelId/levelTerm/levelValue/levelDisplay/
 * normalizedGrade replace the old hardcoded academicLevelGrade
 * ("Grade %d") — this is the student-facing "Class 10"/"Grade 10"/
 * "Year 10" selection, denormalized at booking time so a later admin
 * rename of the EducationSystemLevel's label never rewrites a booking's
 * historical display (§40). academicLevelId/academicLevelName remain
 * as the internal broad-band classification link.
 */
final readonly class BookingAcademicContextData
{
    public function __construct(
        public int $countryId,
        public ?string $countryCode,
        public string $countryName,
        public string $educationSystemId,
        public ?string $educationSystemCode,
        public string $educationSystemName,
        public string $academicLevelId,
        public string $academicLevelName,
        public string $educationSystemLevelId,
        public string $levelTerm,
        public string $levelValue,
        public string $levelDisplay,
        public ?int $normalizedGrade,
        public string $subjectId,
        public string $subjectName,
        public ?string $subjectSlug,
        public string $curriculumId,
        public string $curriculumName,
        public ?string $curriculumSlug,
        public string $curriculumVersionId,
        public int $curriculumVersionNumber,
    ) {}

    /** The plain Curriculum-domain context, for InstructorAcademicEligibilityResolver / AssignmentCriteriaData. */
    public function toAcademicContextData(): AcademicContextData
    {
        return new AcademicContextData(
            countryId: $this->countryId,
            educationSystemId: $this->educationSystemId,
            academicLevelId: $this->academicLevelId,
            subjectId: $this->subjectId,
            curriculumId: $this->curriculumId,
            curriculumVersionId: $this->curriculumVersionId,
        );
    }
}
