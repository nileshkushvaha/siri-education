<?php

declare(strict_types=1);

namespace App\Booking\Repositories;

use App\Booking\Contracts\TeacherCandidateRepositoryInterface;
use App\Booking\DTOs\AssignmentCriteriaData;
use App\Curriculum\Services\InstructorAcademicEligibilityResolver;
use App\Enums\InstructorStatus;
use App\Models\InstructorCurriculumEligibility;
use App\Models\InstructorSubjectTopic;
use App\Models\SubjectTopic;
use App\Models\TeacherSubject;
use App\Models\User;
use Illuminate\Support\Collection;

final class TeacherCandidateRepository implements TeacherCandidateRepositoryInterface
{
    public function __construct(
        private readonly InstructorAcademicEligibilityResolver $academicEligibility,
    ) {}

    /**
     * Phase 3 (§14/§37): when $criteria carries a resolved
     * AcademicContextData, the candidate SET itself is narrowed to
     * instructors with an active InstructorCurriculumEligibility for
     * this exact education system + curriculum, BEFORE ranking ever
     * runs — never "pick then fail afterward". Reuses
     * InstructorCurriculumEligibility::active() (the same scope
     * InstructorAcademicEligibilityResolver itself queries), so no
     * eligibility rule is duplicated — only composed in bulk.
     */
    public function eligible(AssignmentCriteriaData $criteria): Collection
    {
        $teacherIds = TeacherSubject::query()
            ->forSubject($criteria->subject)
            ->coveringGrade($criteria->grade)
            ->pluck('teacher_id')
            ->unique();

        if ($teacherIds->isEmpty()) {
            return new Collection;
        }

        $query = User::query()
            ->whereIn('id', $teacherIds)
            ->where('status', User::STATUS_ACTIVE)
            ->whereHas('profile', fn ($q) => $q->whereIn('instructor_status', InstructorStatus::bookable()))
            ->with('profile');

        if ($criteria->academicContext !== null
            && $criteria->academicContext->educationSystemId !== null
            && $criteria->academicContext->curriculumId !== null) {
            $academicallyEligibleIds = InstructorCurriculumEligibility::query()
                ->where('education_system_id', $criteria->academicContext->educationSystemId)
                ->where('curriculum_id', $criteria->academicContext->curriculumId)
                ->active()
                ->pluck('teacher_id');

            $query->whereIn('id', $academicallyEligibleIds);
        }

        return $query->get();
    }

    public function isEligible(int $teacherId, AssignmentCriteriaData $criteria): bool
    {
        $baseEligible = $this->isApprovedTeacher($teacherId)
            && TeacherSubject::query()
                ->where('teacher_id', $teacherId)
                ->forSubject($criteria->subject)
                ->coveringGrade($criteria->grade)
                ->exists();

        if (! $baseEligible) {
            return false;
        }

        if ($criteria->academicContext === null) {
            return true;
        }

        $instructor = User::find($teacherId);

        return $instructor !== null && $this->academicEligibility->isEligible($instructor, $criteria->academicContext);
    }

    /** Approved/published instructor whose account is also currently active — not just profile-approved. */
    public function isApprovedTeacher(int $teacherId): bool
    {
        return User::query()
            ->whereKey($teacherId)
            ->where('status', User::STATUS_ACTIVE)
            ->whereHas('profile', fn ($q) => $q->whereIn('instructor_status', InstructorStatus::bookable()))
            ->exists();
    }

    public function teachesTopic(int $teacherId, SubjectTopic $topic, ?int $grade): bool
    {
        return InstructorSubjectTopic::query()
            ->where('teacher_id', $teacherId)
            ->where('subject_topic_id', $topic->id)
            ->bookable()
            ->coveringGrade($grade)
            ->exists();
    }

    public function availableSubjects(): Collection
    {
        return TeacherSubject::query()
            ->whereHas('teacher', fn ($q) => $q->where('status', User::STATUS_ACTIVE))
            ->whereHas('teacher.profile', fn ($q) => $q->whereIn('instructor_status', InstructorStatus::bookable()))
            ->distinct()
            ->orderBy('subject')
            ->pluck('subject');
    }
}
