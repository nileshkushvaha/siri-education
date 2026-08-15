<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\DTOs\BookingAcademicContextData;
use App\Booking\Exceptions\BookingException;
use App\Booking\Support\AcademicFlowCopy;
use App\Country\Enums\CountryFeature;
use App\Models\Country;
use App\Models\EducationSystem;
use App\Models\EducationSystemLevel;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The Free Demo flow's binding of the shared
 * BookingAcademicContextResolver: CountryFeature::CountryAcademicBooking
 * as the gate, AcademicFlowCopy::forDemo() as the wording.
 *
 * Phase 3 built this class as the only country-aware academic
 * composition layer. Phase 4D needed the same chain for packages and
 * package-funded paid booking, so the algorithm moved to
 * BookingAcademicContextResolver and this became a wrapper. That is
 * deliberate: Demo Lessons is the only user-facing switch, while this
 * internal feature identifier keeps the shared resolver composition
 * explicit. There is still exactly one implementation of the chain.
 *
 * Demo always requires the student to CHOOSE a curriculum (Phase 3
 * §7/§9 progressive selection), so it never uses the shared resolver's
 * auto-resolve mode.
 */
final class DemoAcademicContextResolver
{
    public function __construct(
        private readonly BookingAcademicContextResolver $resolver,
    ) {}

    /** The student's server-owned Country — never trusted from client input (§6). */
    public function studentCountry(User $student): ?Country
    {
        return $this->resolver->studentCountry($student);
    }

    /**
     * Resolves and validates the full academic context for a Free Demo
     * booking request, re-run at every call site that needs current
     * truth (candidate narrowing, and again immediately before Booking
     * creation — §27 forbids trusting an earlier resolve).
     *
     * @throws BookingException
     */
    public function resolveForDemo(
        User $student,
        ?string $educationSystemId,
        ?string $educationSystemLevelId,
        ?string $subjectId,
        ?string $curriculumId,
    ): ?BookingAcademicContextData {
        return $this->resolver->resolve(
            student: $student,
            feature: CountryFeature::CountryAcademicBooking,
            copy: AcademicFlowCopy::forDemo(),
            educationSystemId: $educationSystemId,
            educationSystemLevelId: $educationSystemLevelId,
            subjectId: $subjectId,
            curriculumId: $curriculumId,
        );
    }

    // ── Progressive option loading (BookingWizard §7/§9) ────────────────────

    public function educationSystemsFor(Country $country, ?User $lockedInstructor = null): Collection
    {
        return $this->resolver->educationSystemsFor($country, $lockedInstructor);
    }

    public function levelsFor(Country $country, EducationSystem $system): Collection
    {
        return $this->resolver->levelsFor($country, $system);
    }

    public function subjectsFor(Country $country, EducationSystem $system, EducationSystemLevel $level, ?User $lockedInstructor = null): Collection
    {
        return $this->resolver->subjectsFor($country, $system, $level, $lockedInstructor);
    }

    public function curriculaFor(Country $country, EducationSystem $system, EducationSystemLevel $level, Subject $subject, ?User $lockedInstructor = null): Collection
    {
        return $this->resolver->curriculaFor($country, $system, $level, $subject, $lockedInstructor);
    }
}
