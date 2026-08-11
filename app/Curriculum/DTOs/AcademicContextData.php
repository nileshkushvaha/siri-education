<?php

declare(strict_types=1);

namespace App\Curriculum\DTOs;

/**
 * An immutable, already-validated slice of the Country -> EducationSystem
 * -> AcademicLevel -> Subject -> Curriculum -> CurriculumVersion chain
 * (mirrors AssignmentCriteriaData's readonly-class shape). Not every
 * resolver call populates every field — e.g. "systems available in a
 * country" only ever sets country_id.
 *
 * Deliberately carries no Booking-specific properties (no
 * instructor_id, slot, price, currency, wallet, package) — those
 * domains consume this context later, they do not extend it.
 *
 * This is a pure data carrier: nothing in this class validates that
 * the ids it holds are a coherent, currently-eligible combination.
 * Only AcademicContextResolver may construct one after running the
 * full validation chain — client-supplied ids are never trusted into
 * an instance of this class without going through the resolver first.
 */
final readonly class AcademicContextData
{
    public function __construct(
        public ?int $countryId = null,
        public ?string $educationSystemId = null,
        public ?string $academicLevelId = null,
        public ?string $subjectId = null,
        public ?string $curriculumId = null,
        public ?string $curriculumVersionId = null,
    ) {}
}
