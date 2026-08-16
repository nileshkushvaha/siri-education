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
            // Re-checked INSIDE the transaction so the locked profile
            // read serializes against a concurrent suspension (see
            // assertEligibleForStudentAction()).
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

    /**
     * Every favourite the student has, profiles eager-loaded, unfiltered
     * and unlimited.
     *
     * Exists for callers that need BOTH the complete favourite set and a
     * bookable subset in one request — RecommendationService previously
     * hit this relationship twice (all ids to exclude, then a bookable
     * sample to personalise from) for a single recommendation build.
     * Favourites are a small hand-curated set, so reading them once and
     * partitioning in memory is cheaper than a second round trip.
     *
     * @return Collection<int, User>
     */
    public function allFavorites(User $student): Collection
    {
        return $student->favoriteInstructors()->with('profile')->get();
    }

    /**
     * The single definition of "this instructor can currently be booked".
     *
     * `bookableFavorites()` expresses the same rule in SQL. Callers that
     * already hold hydrated User models must not re-implement it — this
     * is the one place the rule lives for in-memory checks, and
     * ensureCanFavorite() uses it too so the write path and the read
     * paths cannot drift apart.
     */
    public function isBookable(User $instructor): bool
    {
        return $instructor->status === User::STATUS_ACTIVE
            && $instructor->profile?->profile_visibility === 'public'
            && in_array($instructor->profile?->instructor_status?->value, InstructorStatus::bookableValues(), true);
    }

    private function ensureCanFavorite(User $student, User $instructor): void
    {
        if ($student->id === $instructor->id) {
            throw ValidationException::withMessages(['instructor' => 'You cannot favorite your own profile.']);
        }

        if (! $student->hasRole('student')) {
            throw ValidationException::withMessages(['student' => 'Only student accounts can favorite instructors.']);
        }

        if (! $instructor->hasRole('instructor') || ! $this->isBookable($instructor)) {
            throw ValidationException::withMessages(['instructor' => 'Only approved, public instructors can be favorited.']);
        }
    }
}
