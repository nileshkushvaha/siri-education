<?php

declare(strict_types=1);

namespace App\Services\Instructor;

use App\Booking\Services\MarketplaceCountryResolver;
use App\Enums\AcademicStatus;
use App\Enums\LearningPlanStatus;
use App\Models\StudentLearningPlan;
use App\Models\User;
use App\Services\Student\StudentFavoriteInstructorService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * SRS Chapter 8 §8.10/§8.19-FR#4/FR#10 — the single
 * authoritative entry point for marketplace/dashboard recommendation
 * sections. Every method returns InstructorService::card() output
 * (typed instructor-card arrays, pricing included) built on
 * InstructorService::baseQuery() — the exact same eligibility, subject,
 * and pricing machinery the existing listing/detail pages already use.
 * No parallel discovery engine, no independent price calculation, no
 * random ordering, no opaque scoring: every section is a plain,
 * deterministic SQL ORDER BY.
 */
final class RecommendationService
{
    private const int PERSONALIZATION_FAVORITE_SAMPLE = 20;

    public function __construct(
        private readonly InstructorService $instructors,
        private readonly MarketplaceCountryResolver $marketplaceCountry,
        private readonly StudentFavoriteInstructorService $favorites,
    ) {}

    /** Admin-curated via UserProfile::is_featured/featured_order. */
    public function featured(Request $request, int $limit = 4): Collection
    {
        return $this->instructors->featuredCards($request, $limit);
    }

    /**
     * Subject-matched "keep exploring" instructors for a specific
     * profile. The single entry point for external callers (this CMS
     * block, tests); InstructorService::publicProfile() calls
     * InstructorService::related() directly to avoid a circular
     * dependency (it would otherwise need to depend on this service,
     * which itself depends on InstructorService) — both paths run the
     * exact same deterministic query.
     */
    public function related(User $instructor, Request $request, int $limit = 3): Collection
    {
        $country = $this->marketplaceCountry->resolve($request->user(), $request)->country;

        return $this->instructors->cardsFor($this->instructors->related($instructor, $limit), $country);
    }

    /** Highly rated / most reviewed — SRS §8.10 "Popular Instructors". */
    public function popular(Request $request, int $limit = 4, array $excludeInstructorIds = []): Collection
    {
        $country = $this->marketplaceCountry->resolve($request->user(), $request)->country;

        $query = $this->instructors->baseQuery();

        if ($excludeInstructorIds !== []) {
            $query->whereNotIn('users.id', $excludeInstructorIds);
        }

        $instructors = $this->instructors->applyPopularityOrder($query)->limit($limit)->get();

        return $this->instructors->cardsFor($instructors, $country);
    }

    /** SRS §8.10/§8.13 "New Instructors" — controlled marketplace exposure for recently joined instructors. */
    public function newInstructors(Request $request, int $limit = 4): Collection
    {
        $country = $this->marketplaceCountry->resolve($request->user(), $request)->country;

        $instructors = $this->instructors->baseQuery()
            ->orderByDesc('users.created_at')
            ->orderBy('users.id')
            ->limit($limit)
            ->get();

        return $this->instructors->cardsFor($instructors, $country);
    }

    /**
     * SRS §8.10/§8.11 "Recommended for You" — subject overlap with the
     * student's own active learning plans and favorited instructors'
     * subjects (both read for the CURRENT student only — never another
     * student's activity). Already-favorited instructors are excluded
     * (the point is surfacing someone NEW). Guests, non-students, and
     * students with no derivable signal fall back to popular() — a
     * safe, never-empty, never-personal default.
     *
     * $viewer is explicit (not read from $request->user()) so a caller
     * with a specific student already in hand — e.g. StudentDashboardService,
     * which builds its whole summary() for one given User — can never
     * accidentally compute recommendations for a different user than
     * the one it's building the rest of the response for.
     */
    public function recommendedForYou(?User $viewer, Request $request, int $limit = 4): Collection
    {
        if ($viewer === null || ! $viewer->hasRole('student')) {
            return $this->popular($request, $limit);
        }

        $student = $viewer;

        $excludeIds = $this->favoritedInstructorIds($student);
        $signalSlugs = $this->personalizationSignalSubjectSlugs($student);

        if ($signalSlugs->isEmpty()) {
            return $this->popular($request, $limit, $excludeIds);
        }

        $country = $this->marketplaceCountry->resolve($student, $request)->country;

        $query = $this->instructors->baseQuery()
            ->whereNotIn('users.id', $excludeIds)
            ->whereHas('teacherSubjects.subjectMaster', fn (Builder $q) => $q->whereIn('slug', $signalSlugs));

        $matched = $this->instructors->applyPopularityOrder($query)->limit($limit)->get();

        if ($matched->count() < $limit) {
            $more = $this->instructors->applyPopularityOrder(
                $this->instructors->baseQuery()
                    ->whereNotIn('users.id', [...$excludeIds, ...$matched->pluck('id')->all()]),
            )->limit($limit - $matched->count())->get();

            $matched = $matched->concat($more);
        }

        return $this->instructors->cardsFor($matched, $country);
    }

    /** @return list<int> */
    private function favoritedInstructorIds(User $student): array
    {
        return $student->favoriteInstructors()->pluck('users.id')->all();
    }

    /** @return Collection<int, string> */
    private function personalizationSignalSubjectSlugs(User $student): Collection
    {
        $planSlugs = StudentLearningPlan::query()
            ->where('student_user_id', $student->id)
            ->whereNotIn('status', [LearningPlanStatus::Completed->value, LearningPlanStatus::Archived->value])
            ->whereHas('subject', fn (Builder $q) => $q->where('status', AcademicStatus::Active))
            ->with('subject:id,slug,status')
            ->get()
            ->pluck('subject.slug')
            ->filter();

        $favoriteSlugs = $this->favorites->bookableFavorites($student, self::PERSONALIZATION_FAVORITE_SAMPLE)
            ->flatMap(fn (User $instructor) => $this->instructors->activeSubjectSlugsFor($instructor));

        return $planSlugs->merge($favoriteSlugs)->unique()->values();
    }
}
