<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\DTOs\BookingAcademicContextData;
use App\Booking\Exceptions\BookingException;
use App\Country\Enums\CountryFeature;
use App\Country\Services\CountryFeatureResolver;
use App\Country\Services\CountryResolver;
use App\Curriculum\Exceptions\AcademicContextException;
use App\Curriculum\Services\AcademicContextResolver;
use App\Curriculum\Services\InstructorAcademicEligibilityResolver;
use App\Models\Country;
use App\Models\Curriculum;
use App\Models\CurriculumVersion;
use App\Models\EducationSystem;
use App\Models\EducationSystemLevel;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Booking-domain composition layer (Phase 3 spec §12, revised by Phase
 * 3.1 spec §11) — the ONLY place that decides whether a Free Demo
 * booking must go through the country-aware academic flow, and turns
 * raw, client-supplied education-system/level/subject/curriculum ids
 * into a trusted, immutable snapshot. Deliberately does not duplicate
 * AcademicContextResolver/InstructorAcademicEligibilityResolver's own
 * validation logic — it only orchestrates: feature gate → student
 * Country (server-resolved, never trusted from client input) →
 * AcademicContextResolver::resolveContextForLevel() → snapshot DTO.
 *
 * Returns null to mean "fall back to the legacy free-text subject/grade
 * Demo flow" — this only ever happens because the feature is globally
 * off, or explicitly disabled for the student's resolved country
 * (§41 — per-country rollout). Once the feature is in effect for a
 * country, every failure throws BookingException instead of silently
 * downgrading (§10/§26) — including a missing/inactive student
 * Country (§6) and an incomplete academic selection.
 *
 * Phase 3.1: the student selects one EducationSystemLevel ("Class 10")
 * directly — never an AcademicLevel band + a separate numeric grade.
 * The AcademicLevel and normalized grade are both implied by the
 * chosen level. A level with no normalized_grade is currently
 * unsupported for Demo booking: TeacherSubject/candidate matching is
 * numeric-grade-based throughout the codebase, and no existing
 * business rule defines a subject-only fallback — inventing one is out
 * of scope for this phase (§9).
 */
final class DemoAcademicContextResolver
{
    public function __construct(
        private readonly CountryResolver $countryResolver,
        private readonly CountryFeatureResolver $countryFeatures,
        private readonly AcademicContextResolver $academicContextResolver,
        private readonly InstructorAcademicEligibilityResolver $instructorEligibility,
    ) {}

    /** The student's server-owned Country — never trusted from client input (§6). */
    public function studentCountry(User $student): ?Country
    {
        return $this->countryResolver->forStudent($student);
    }

    public function isEnabledForCountry(?Country $country): bool
    {
        return $this->countryFeatures->isEnabled(CountryFeature::CountryAcademicBooking, $country);
    }

    /** Whether the feature is on at all, ignoring any specific country's override — a cheap "is this flow even relevant" check. */
    public function isEnabledGlobally(): bool
    {
        return $this->countryFeatures->isEnabled(CountryFeature::CountryAcademicBooking, null);
    }

    /**
     * Resolves and validates the full academic context for a Free Demo
     * booking request, re-run at every call site that needs current
     * truth (candidate narrowing, and again immediately before Booking
     * creation — Phase 3 spec §27 forbids trusting an earlier resolve).
     *
     * @throws BookingException when the feature is in effect for this
     *                          student's country but the country, or the
     *                          selection, is missing/invalid/stale, or
     *                          the selected level has no normalized_grade
     */
    public function resolveForDemo(
        User $student,
        ?string $educationSystemId,
        ?string $educationSystemLevelId,
        ?string $subjectId,
        ?string $curriculumId,
    ): ?BookingAcademicContextData {
        if (! $this->isEnabledGlobally()) {
            return null;
        }

        $country = $this->studentCountry($student);

        if ($country === null || $country->status !== 'active') {
            throw new BookingException('Please complete your profile country before booking a demo lesson.');
        }

        if (! $this->isEnabledForCountry($country)) {
            return null;
        }

        if ($educationSystemId === null || $educationSystemLevelId === null || $subjectId === null || $curriculumId === null) {
            throw new BookingException('Please complete your education system, level, subject, and curriculum selections before booking a demo lesson.');
        }

        $system = EducationSystem::find($educationSystemId);
        $level = EducationSystemLevel::find($educationSystemLevelId);
        $subject = Subject::find($subjectId);
        $curriculum = Curriculum::find($curriculumId);

        if ($system === null || $level === null || $subject === null || $curriculum === null) {
            throw new BookingException('One or more of your academic selections is no longer available. Please choose again.');
        }

        if ($level->normalized_grade === null) {
            throw new BookingException('This level is not currently supported for demo booking. Please select a different level.');
        }

        try {
            $context = $this->academicContextResolver->resolveContextForLevel($country, $system, $level, $subject, $curriculum);
        } catch (AcademicContextException $e) {
            throw new BookingException($e->getMessage());
        }

        $version = CurriculumVersion::find($context->curriculumVersionId);

        if ($version === null) {
            throw new BookingException('The selected curriculum is no longer available for booking.');
        }

        return $this->buildSnapshot($country, $system, $level, $subject, $curriculum, $version);
    }

    // ── Progressive option loading (BookingWizard §7/§9) ────────────────────
    //
    // Deliberately thin composition over AcademicContextResolver /
    // InstructorAcademicEligibilityResolver — never a duplicate rules
    // engine. When a locked Instructor is supplied, the returned options
    // are additionally intersected with that Instructor's eligibility
    // (§9 — a student must never be offered a System/Curriculum a locked
    // Instructor cannot teach), never the other way around.

    /** Education Systems available for $country, narrowed to $lockedInstructor's eligibility when locked. */
    public function educationSystemsFor(Country $country, ?User $lockedInstructor = null): Collection
    {
        $systems = $this->academicContextResolver->educationSystemsForCountry($country);

        if ($lockedInstructor === null) {
            return $systems;
        }

        $eligibleIds = $this->instructorEligibility->eligibleEducationSystemsFor($lockedInstructor)->pluck('id');

        return $systems->filter(fn (EducationSystem $system): bool => $eligibleIds->contains($system->id))->values();
    }

    /**
     * Phase 3.1 — the exact, student-selectable levels for $system in
     * $country (Class 6..12 / Grade 6..12 / Year 6..12, ...), replacing
     * the old academicLevelsFor(). Returns an empty Collection when
     * none are configured; callers must show an "unavailable" state,
     * never synthesize a 1..12 fallback (§7/§39).
     */
    public function levelsFor(Country $country, EducationSystem $system): Collection
    {
        return $this->academicContextResolver->levelsForSystem($country, $system);
    }

    /** Subjects regionally available for the given System+Level. Not eligibility-scoped (see class docblock — Subject visibility never implies Curriculum selectability). */
    public function subjectsFor(Country $country, EducationSystem $system, EducationSystemLevel $level, ?User $lockedInstructor = null): Collection
    {
        $academicLevel = $level->academicLevel;

        if ($academicLevel === null) {
            return new Collection;
        }

        return $this->academicContextResolver->subjectsForContext($country, $system, $academicLevel);
    }

    /** Selectable (currently-Published) Curricula for the given context, narrowed to $lockedInstructor's eligibility when locked. */
    public function curriculaFor(Country $country, EducationSystem $system, EducationSystemLevel $level, Subject $subject, ?User $lockedInstructor = null): Collection
    {
        $academicLevel = $level->academicLevel;

        if ($academicLevel === null) {
            return new Collection;
        }

        $curricula = $this->academicContextResolver->curriculaForContext($country, $system, $academicLevel, $subject);

        if ($lockedInstructor === null) {
            return $curricula;
        }

        $eligibleIds = $this->instructorEligibility->eligibleCurriculaFor($lockedInstructor, $system)->pluck('id');

        return $curricula->filter(fn (Curriculum $curriculum): bool => $eligibleIds->contains($curriculum->id))->values();
    }

    private function buildSnapshot(Country $country, EducationSystem $system, EducationSystemLevel $level, Subject $subject, Curriculum $curriculum, CurriculumVersion $version): BookingAcademicContextData
    {
        $academicLevel = $level->academicLevel;

        return new BookingAcademicContextData(
            countryId: $country->id,
            countryCode: $country->iso2 ?? $country->iso3,
            countryName: $country->name,
            educationSystemId: $system->id,
            educationSystemCode: $system->code,
            educationSystemName: $system->name,
            academicLevelId: $academicLevel->id,
            academicLevelName: $academicLevel->name,
            educationSystemLevelId: $level->id,
            levelTerm: $system->levelTermSingular(),
            levelValue: $level->value,
            levelDisplay: $level->display_label,
            normalizedGrade: $level->normalized_grade,
            subjectId: $subject->id,
            subjectName: $subject->name,
            subjectSlug: $subject->slug,
            curriculumId: $curriculum->id,
            curriculumName: $curriculum->name,
            curriculumSlug: $curriculum->slug,
            curriculumVersionId: $version->id,
            curriculumVersionNumber: $version->version_number,
        );
    }
}
