<?php

declare(strict_types=1);

namespace App\Booking\Repositories;

use App\Booking\Contracts\TeacherCandidateRepositoryInterface;
use App\Booking\DTOs\AssignmentCriteriaData;
use App\Enums\InstructorStatus;
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

    public function isApprovedTeacher(int $teacherId): bool
    {
        return User::query()
            ->whereKey($teacherId)
            ->whereHas('profile', fn ($q) => $q->whereIn('instructor_status', InstructorStatus::bookable()))
            ->exists();
    }

    public function availableSubjects(): Collection
    {
        return TeacherSubject::query()
            ->whereHas('teacher.profile', fn ($q) => $q->whereIn('instructor_status', InstructorStatus::bookable()))
            ->distinct()
            ->orderBy('subject')
            ->pluck('subject');
    }
}
