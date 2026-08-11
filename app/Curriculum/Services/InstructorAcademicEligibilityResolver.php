<?php

declare(strict_types=1);

namespace App\Curriculum\Services;

use App\Curriculum\DTOs\AcademicContextData;
use App\Curriculum\Exceptions\InstructorAcademicEligibilityException;
use App\Models\EducationSystem;
use App\Models\InstructorCurriculumEligibility;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * The single authority answering "can Instructor X teach this already-
 * resolved academic context?" — the Instructor-side counterpart to
 * AcademicContextResolver, which only ever answers "is this academic
 * context itself valid?". Mirrors that class's read-only, side-effect-
 * free shape.
 *
 * Responsibility boundary (do not blur): this resolver validates
 * academic CAPABILITY only. It deliberately does not check instructor
 * account/lifecycle status (active, approved, not suspended, etc.) —
 * that remains TeacherCandidateRepository::isApprovedTeacher()'s job.
 * "Is this instructor bookable" = instructor status AND academic
 * eligibility AND availability AND booking policy, evaluated by a
 * future booking-phase composition layer — never merged into this
 * class.
 *
 * Only ever trusts AcademicContextData produced by
 * AcademicContextResolver::resolveContext() — never raw client ids.
 * Because that method already throws when no Published CurriculumVersion
 * exists, a context reaching isEligible()/assertEligible() here is
 * always "current publication state permitting" — this class does not
 * re-check publication state itself.
 */
final class InstructorAcademicEligibilityResolver
{
    /** Whether an active eligibility row exists for this Instructor against this resolved context's EducationSystem+Curriculum. */
    public function isEligible(User $instructor, AcademicContextData $context): bool
    {
        if ($context->educationSystemId === null || $context->curriculumId === null) {
            return false;
        }

        return InstructorCurriculumEligibility::query()
            ->where('teacher_id', $instructor->id)
            ->where('education_system_id', $context->educationSystemId)
            ->where('curriculum_id', $context->curriculumId)
            ->active()
            ->exists();
    }

    public function assertEligible(User $instructor, AcademicContextData $context): void
    {
        if (! $this->isEligible($instructor, $context)) {
            throw new InstructorAcademicEligibilityException(sprintf(
                'Instructor "%s" is not academically eligible for the resolved curriculum/education-system context.',
                $instructor->name,
            ));
        }
    }

    /** Active Education Systems this instructor is currently approved under (any curriculum). */
    public function eligibleEducationSystemsFor(User $instructor): Collection
    {
        return EducationSystem::query()
            ->active()
            ->whereHas('instructorEligibilities', fn ($q) => $q
                ->where('teacher_id', $instructor->id)
                ->where('is_active', true))
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();
    }

    /** Curricula this instructor is currently approved to teach under the given Education System. */
    public function eligibleCurriculaFor(User $instructor, EducationSystem $system): Collection
    {
        return InstructorCurriculumEligibility::query()
            ->where('teacher_id', $instructor->id)
            ->where('education_system_id', $system->id)
            ->active()
            ->with('curriculum')
            ->get()
            ->map(fn (InstructorCurriculumEligibility $e) => $e->curriculum)
            ->filter()
            ->values();
    }
}
