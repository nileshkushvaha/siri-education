<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\DTOs\BookingAcademicContextData;
use App\Booking\Exceptions\BookingException;
use App\Booking\Support\AcademicFlowCopy;
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
 * Phase 4D — the booking-type-neutral composition layer that turns raw,
 * client-supplied education-system/level/subject/curriculum ids into a
 * trusted, immutable academic snapshot.
 *
 * This is Phase 3's DemoAcademicContextResolver with the two demo-only
 * assumptions lifted out and passed in instead:
 *
 *   - WHICH feature gates the flow (CountryFeature), because packages
 *     must not hang off the demo-lessons dependency chain; and
 *   - the student-facing WORDING (AcademicFlowCopy).
 *
 * Everything else — feature gate → server-resolved Country →
 * AcademicContextResolver::resolveContextForLevel() → snapshot DTO — is
 * the same single algorithm, shared rather than reimplemented (spec
 * §1: "Do not duplicate country/system/curriculum resolution
 * algorithms"). DemoAcademicContextResolver is now a thin wrapper over
 * this class and keeps its exact Phase 3 behavior and messages.
 *
 * Like its predecessor it deliberately caches nothing across calls:
 * callers re-run it at each point that needs current truth (candidate
 * narrowing, and again immediately before persistence) so a race
 * between UI render and submit is caught rather than trusted.
 *
 * Returns null to mean "this flow does not apply — fall back to the
 * caller's legacy path", which happens ONLY because the gating feature
 * is globally off or disabled for the student's resolved country. Once
 * the feature is in effect, every failure throws BookingException
 * instead of silently downgrading.
 */
final class BookingAcademicContextResolver
{
    public function __construct(
        private readonly CountryResolver $countryResolver,
        private readonly CountryFeatureResolver $countryFeatures,
        private readonly AcademicContextResolver $academicContextResolver,
        private readonly InstructorAcademicEligibilityResolver $instructorEligibility,
    ) {}

    /** The student's server-owned Country — never trusted from client input. */
    public function studentCountry(User $student): ?Country
    {
        return $this->countryResolver->forStudent($student);
    }

    public function isEnabledForCountry(CountryFeature $feature, ?Country $country): bool
    {
        return $this->countryFeatures->isEnabled($feature, $country);
    }

    /** Whether $feature is on at all, ignoring any specific country's override. */
    public function isEnabledGlobally(CountryFeature $feature): bool
    {
        return $this->countryFeatures->isEnabled($feature, null);
    }

    /**
     * Resolves and validates the full academic context for a student.
     *
     * $curriculumId may be null when the caller wants the curriculum
     * RESOLVED rather than chosen (the package flow — spec §11: a
     * deterministic single valid result must not become another
     * dropdown). When exactly one curriculum is available for the
     * context it is selected automatically; zero or more than one is an
     * explicit failure, never a silent pick.
     *
     * @throws BookingException when the feature is in effect for this
     *                          student's country but the country, or the
     *                          selection, is missing/invalid/stale, or
     *                          the selected level has no normalized_grade
     */
    public function resolve(
        User $student,
        CountryFeature $feature,
        AcademicFlowCopy $copy,
        ?string $educationSystemId,
        ?string $educationSystemLevelId,
        ?string $subjectId,
        ?string $curriculumId,
        bool $autoResolveCurriculum = false,
    ): ?BookingAcademicContextData {
        if (! $this->isEnabledGlobally($feature)) {
            return null;
        }

        $country = $this->studentCountry($student);

        if ($country === null || $country->status !== 'active') {
            throw new BookingException($copy->missingCountry);
        }

        if (! $this->isEnabledForCountry($feature, $country)) {
            return null;
        }

        $curriculumRequired = ! $autoResolveCurriculum;

        if ($educationSystemId === null || $educationSystemLevelId === null || $subjectId === null || ($curriculumRequired && $curriculumId === null)) {
            throw new BookingException($copy->incompleteSelection);
        }

        $system = EducationSystem::find($educationSystemId);
        $level = EducationSystemLevel::find($educationSystemLevelId);
        $subject = Subject::find($subjectId);

        if ($system === null || $level === null || $subject === null) {
            throw new BookingException($copy->staleSelection);
        }

        // §7/§9: normalized_grade is what TeacherSubject.grade_from/
        // grade_to matching needs. A level without one fails safely —
        // never a manufactured numeric fallback.
        if ($level->normalized_grade === null) {
            throw new BookingException($copy->unsupportedLevel);
        }

        $curriculum = $curriculumId !== null
            ? Curriculum::find($curriculumId)
            : $this->soleCurriculumFor($country, $system, $level, $subject, $copy);

        if ($curriculum === null) {
            throw new BookingException($copy->staleSelection);
        }

        try {
            $context = $this->academicContextResolver->resolveContextForLevel($country, $system, $level, $subject, $curriculum);
        } catch (AcademicContextException $e) {
            throw new BookingException($e->getMessage());
        }

        $version = CurriculumVersion::find($context->curriculumVersionId);

        if ($version === null) {
            throw new BookingException($copy->unavailableCurriculum);
        }

        return $this->buildSnapshot($country, $system, $level, $subject, $curriculum, $version);
    }

    // ── Progressive option loading ─────────────────────────────────────────
    //
    // Deliberately thin composition over AcademicContextResolver /
    // InstructorAcademicEligibilityResolver — never a duplicate rules
    // engine. When a locked Instructor is supplied, the returned options
    // are additionally intersected with that Instructor's eligibility (a
    // student/instructor must never be offered a System/Curriculum the
    // locked Instructor cannot teach), never the other way around.

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
     * The exact, student-selectable levels for $system in $country
     * (Class 6..12 / Grade 6..12 / Year 6..12, ...). Returns an empty
     * Collection when none are configured; callers must show an
     * "unavailable" state, never synthesize a 1..12 fallback.
     */
    public function levelsFor(Country $country, EducationSystem $system): Collection
    {
        return $this->academicContextResolver->levelsForSystem($country, $system);
    }

    /** Subjects regionally available for the given System+Level. */
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

    public function buildSnapshot(Country $country, EducationSystem $system, EducationSystemLevel $level, Subject $subject, Curriculum $curriculum, CurriculumVersion $version): BookingAcademicContextData
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

    // ── Internals ─────────────────────────────────────────────────────────

    /**
     * §11 — the curriculum is DISPLAYED, not chosen, when the context
     * determines exactly one. Anything other than exactly one is a
     * failure the caller must surface: zero means the context is not
     * teachable, and more than one is genuine ambiguity that must never
     * be resolved by picking first().
     */
    private function soleCurriculumFor(Country $country, EducationSystem $system, EducationSystemLevel $level, Subject $subject, AcademicFlowCopy $copy): ?Curriculum
    {
        // curriculaFor() validates the Country/System/Level/Subject
        // chain on the way to listing curricula, so a forged or
        // mismatched selection surfaces HERE — before resolveContext()
        // ever runs. That is a domain failure for this flow's caller,
        // not a Curriculum-domain error to leak: without this wrap an
        // AcademicContextException escapes past every caller's
        // `catch (BookingException)` and surfaces as an unhandled
        // exception rather than a clean, translated refusal.
        try {
            $curricula = $this->curriculaFor($country, $system, $level, $subject);
        } catch (AcademicContextException $e) {
            throw new BookingException($e->getMessage());
        }

        if ($curricula->count() === 1) {
            return $curricula->first();
        }

        if ($curricula->isEmpty()) {
            throw new BookingException($copy->unavailableCurriculum);
        }

        throw new BookingException('More than one curriculum matches this selection, so it cannot be resolved automatically. Please contact support.');
    }
}
