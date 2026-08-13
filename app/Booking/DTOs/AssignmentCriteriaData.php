<?php

declare(strict_types=1);

namespace App\Booking\DTOs;

use App\Curriculum\DTOs\AcademicContextData;
use Carbon\CarbonImmutable;

/**
 * What the guest/student needs — never which teacher they want.
 * The assignment engine turns this into a teacher.
 */
final readonly class AssignmentCriteriaData
{
    public function __construct(
        public string $typeKey,
        public string $subject,
        public int $grade,
        public CarbonImmutable $startsAt,
        public int $durationMinutes,
        public ?string $timezone = null,
        public ?string $language = null, // reserved for future language matching
        /**
         * Phase 3 — set only for a country-aware Free Demo request
         * (never for paid/recurring in this phase). When present,
         * TeacherCandidateRepository narrows the candidate SET itself to
         * instructors with an active InstructorCurriculumEligibility for
         * this exact education system + curriculum — never a
         * pick-then-check-afterward pattern. Null preserves every
         * existing (legacy/paid) candidate-selection behavior unchanged.
         */
        public ?AcademicContextData $academicContext = null,
    ) {}

    public function endsAt(): CarbonImmutable
    {
        return $this->startsAt->addMinutes($this->durationMinutes);
    }
}
