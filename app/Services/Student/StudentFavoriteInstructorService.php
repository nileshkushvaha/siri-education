<?php

declare(strict_types=1);

namespace App\Services\Student;

use App\Enums\InstructorStatus;
use App\Models\StudentFavoriteInstructor;
use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StudentFavoriteInstructorService
{
    public function __construct(
        private readonly AuditTrailService $audit,
        private readonly StudentLifecycleService $lifecycle,
    ) {}

    public function favorite(User $student, User $instructor): StudentFavoriteInstructor
    {
        $this->ensureCanFavorite($student, $instructor);

        return DB::transaction(function () use ($student, $instructor): StudentFavoriteInstructor {
            // Phase 24H.2 — GAP-013: re-checked INSIDE the transaction so
            // the locked profile read serializes against a concurrent
            // suspension (see assertEligibleForStudentAction()).
            $this->lifecycle->assertEligibleForStudentAction($student);

            $favorite = StudentFavoriteInstructor::firstOrCreate([
                'student_user_id' => $student->id,
                'instructor_user_id' => $instructor->id,
            ]);

            if ($favorite->wasRecentlyCreated) {
                $this->audit->logUser(
                    $student,
                    'student_favorites',
                    'student_favorite_instructor_added',
                    'Student favorite instructor added.',
                    $favorite,
                    ['favorite_id' => $favorite->id, 'instructor_user_id' => $instructor->id],
                );
            }

            return $favorite;
        });
    }

    public function unfavorite(User $student, User $instructor): void
    {
        DB::transaction(function () use ($student, $instructor): void {
            $this->lifecycle->assertEligibleForStudentAction($student);

            $favorite = StudentFavoriteInstructor::query()
                ->where('student_user_id', $student->id)
                ->where('instructor_user_id', $instructor->id)
                ->first();

            if (! $favorite) {
                return;
            }

            $favorite->delete();

            $this->audit->logUser(
                $student,
                'student_favorites',
                'student_favorite_instructor_removed',
                'Student favorite instructor removed.',
                null,
                ['instructor_user_id' => $instructor->id],
            );
        });
    }

    /**
     * @return Collection<int, User>
     */
    public function bookableFavorites(User $student, int $limit = 6): Collection
    {
        return $student->favoriteInstructors()
            ->with('profile')
            ->where('users.status', User::STATUS_ACTIVE)
            ->whereHas('profile', function ($query): void {
                $query->where('profile_visibility', 'public')
                    ->whereIn('instructor_status', InstructorStatus::bookableValues());
            })
            ->limit($limit)
            ->get();
    }

    private function ensureCanFavorite(User $student, User $instructor): void
    {
        if ($student->id === $instructor->id) {
            throw ValidationException::withMessages(['instructor' => 'You cannot favorite your own profile.']);
        }

        if (! $student->hasRole('student')) {
            throw ValidationException::withMessages(['student' => 'Only student accounts can favorite instructors.']);
        }

        $isBookable = $instructor->isActive()
            && $instructor->hasRole('instructor')
            && $instructor->profile?->profile_visibility === 'public'
            && in_array($instructor->profile?->instructor_status?->value, InstructorStatus::bookableValues(), true);

        if (! $isBookable) {
            throw ValidationException::withMessages(['instructor' => 'Only approved, public instructors can be favorited.']);
        }
    }
}
