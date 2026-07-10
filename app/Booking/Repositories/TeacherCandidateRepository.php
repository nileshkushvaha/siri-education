<?php

declare(strict_types=1);

namespace App\Booking\Repositories;

use App\Booking\Contracts\TeacherCandidateRepositoryInterface;
use App\Booking\DTOs\AssignmentCriteriaData;
use App\Enums\InstructorStatus;
use App\Models\InstructorSubjectTopic;
use App\Models\SubjectTopic;
use App\Models\TeacherSubject;
use App\Models\User;
use Illuminate\Support\Collection;

final class TeacherCandidateRepository implements TeacherCandidateRepositoryInterface
{
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

        return User::query()
            ->whereIn('id', $teacherIds)
            ->where('status', User::STATUS_ACTIVE)
            ->whereHas('profile', fn ($q) => $q->whereIn('instructor_status', InstructorStatus::bookable()))
            ->with('profile')
            ->get();
    }

    public function isEligible(int $teacherId, AssignmentCriteriaData $criteria): bool
    {
        return $this->isApprovedTeacher($teacherId)
            && TeacherSubject::query()
                ->where('teacher_id', $teacherId)
                ->forSubject($criteria->subject)
                ->coveringGrade($criteria->grade)
                ->exists();
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
