<?php

declare(strict_types=1);

namespace App\Services\Instructor;

use App\Enums\InstructorStatus;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Services\Profile\UserExperienceService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Read-side helpers only — no CRUD here. Entry points for the public
 * instructor listing, detail page, and related-instructors widget.
 */
final class InstructorService
{
    public function __construct(
        private readonly UserExperienceService $experienceService,
    ) {}

    public function listing(Request $request): LengthAwarePaginator
    {
        $query = $this->baseQuery();

        if ($q = $request->string('q')->trim()->toString()) {
            $this->applyTextSearch($query, $q);
        }

        if ($subject = $request->string('subject')->trim()->toString()) {
            $query->whereHas('teacherSubjects', fn (Builder $subjectQuery) => $subjectQuery->where('subject', $subject));
        }

        if ($language = $request->string('language')->trim()->toString()) {
            $query->where('user_profiles.language', $language);
        }

        if ($request->boolean('available')) {
            $query->whereHas('teacherAvailability', fn (Builder $availabilityQuery) => $availabilityQuery->active());
        }

        $sort = $request->input('sort', 'featured');

        match ($sort) {
            'name' => $query->orderBy('users.name'),
            'newest' => $query->orderByDesc('users.created_at'),
            default => $query->orderByRaw('user_profiles.is_featured DESC')
                ->orderBy('user_profiles.featured_order')
                ->orderBy('users.name'),
        };

        return $query->paginate(12)->withQueryString();
    }

    public function directory(Request $request): LengthAwarePaginator
    {
        return $this->listing($request)
            ->through(fn (User $instructor): array => $this->card($instructor));
    }

    public function filters(): array
    {
        return [
            'subjects' => $this->availableSubjects(),
            'languages' => $this->availableLanguages(),
        ];
    }

    public function featured(int $limit = 4): Collection
    {
        return $this->baseQuery()
            ->where('user_profiles.is_featured', true)
            ->orderBy('user_profiles.featured_order')
            ->orderBy('users.name')
            ->limit($limit)
            ->get();
    }

    public function related(User $instructor, int $limit = 4): Collection
    {
        return $this->baseQuery()
            ->where('users.id', '!=', $instructor->id)
            ->inRandomOrder()
            ->limit($limit)
            ->get();
    }

    public function stats(User $instructor): array
    {
        return [
            'years_experience' => $this->experienceService->yearsOfExperience($instructor),
            'experience_count' => $instructor->experiences()->active()->count(),
            'education_count' => $instructor->educations()->active()->count(),
            'students_count' => 0,
            'avg_rating' => null,
        ];
    }

    public function publicProfile(User $instructor, ?User $viewer = null): array
    {
        abort_unless($instructor->hasRole('instructor'), 404);

        $instructor->loadMissing($this->detailRelations());

        $profile = $instructor->profile;
        abort_unless($profile, 404);

        $isOwner = $viewer && $viewer->id === $instructor->id;
        $canManage = $isOwner || ($viewer && $viewer->can('Update:User'));
        $isBookable = in_array($profile->instructor_status, InstructorStatus::bookable(), true);

        abort_if(! $canManage && (! $instructor->isActive() || ! $isBookable), 403);
        abort_if($profile->profile_visibility === 'private' && ! $canManage, 403);
        abort_if($profile->profile_visibility === 'members_only' && ! $viewer, 403);

        $experiences = $this->experienceService->timeline($instructor);
        $educations = $instructor->educations;
        $currentPosition = $this->experienceService->currentPosition($instructor);
        $stats = $this->stats($instructor);
        $subjects = $this->subjectsFor($instructor);
        $languages = $this->languagesFor($instructor);
        $ratings = $this->ratingsFor($instructor);
        $availabilityPreview = $this->availabilityPreview($instructor);
        $related = $this->related($instructor)->map(fn (User $relatedInstructor): array => $this->card($relatedInstructor));

        $skills = $experiences
            ->flatMap(fn ($experience) => $experience->skills ?? [])
            ->filter()
            ->unique()
            ->values();

        $certificates = $educations->filter(fn ($education) => filled($education->certificate_number));

        return compact(
            'instructor',
            'profile',
            'experiences',
            'educations',
            'currentPosition',
            'stats',
            'subjects',
            'languages',
            'ratings',
            'availabilityPreview',
            'related',
            'skills',
            'certificates',
        );
    }

    public function card(User $instructor): array
    {
        $instructor->loadMissing($this->cardRelations());

        $profile = $instructor->profile;
        $currentPosition = $this->experienceService->currentPosition($instructor);
        $subjects = $this->subjectsFor($instructor)->take(3);
        $languages = $this->languagesFor($instructor)->take(2);
        $ratings = $this->ratingsFor($instructor);
        $availability = $this->availabilityPreview($instructor)->take(2);

        return [
            'model' => $instructor,
            'name' => $instructor->name,
            'url' => route('instructors.show', $instructor),
            'avatar_url' => $profile?->avatarUrl,
            'cover_url' => $instructor->getFirstMediaUrl('instructor_cover'),
            'headline' => $profile?->headline,
            'summary' => $profile?->short_bio ?: Str::limit((string) $profile?->bio, 130),
            'verified' => (bool) $profile?->is_instructor_verified,
            'current_position' => $currentPosition
                ? trim($currentPosition->designation.($currentPosition->organization_name ? ' · '.$currentPosition->organization_name : ''))
                : null,
            'subjects' => $subjects,
            'languages' => $languages,
            'ratings' => $ratings,
            'availability_preview' => $availability,
            'years_experience' => $this->experienceService->yearsOfExperience($instructor),
        ];
    }

    public function subjectsFor(User $instructor): Collection
    {
        $instructor->loadMissing('teacherSubjects');

        return $instructor->teacherSubjects
            ->sortBy('subject')
            ->map(fn ($subject): array => [
                'name' => $this->formatSubject((string) $subject->subject),
                'slug' => (string) $subject->subject,
                'grade_range' => $this->formatGradeRange($subject->grade_from, $subject->grade_to),
            ])
            ->values();
    }

    public function languagesFor(User $instructor): Collection
    {
        $language = $instructor->profile?->language;

        if (! is_string($language) || trim($language) === '') {
            return collect();
        }

        return collect(preg_split('/[,|]/', $language) ?: [])
            ->map(fn (string $item): string => trim($item))
            ->filter()
            ->values();
    }

    public function ratingsFor(User $instructor): array
    {
        return [
            'average' => null,
            'count' => 0,
        ];
    }

    public function availabilityPreview(User $instructor, int $limit = 4): Collection
    {
        $instructor->loadMissing('teacherAvailability');

        return $instructor->teacherAvailability
            ->filter(fn ($availability): bool => (bool) $availability->is_active)
            ->sortBy(fn ($availability): string => $availability->day_of_week->value.'-'.$availability->start_time)
            ->take($limit)
            ->map(fn ($availability): array => [
                'day' => $availability->day_of_week->label(),
                'time' => $this->formatTimeRange((string) $availability->start_time, (string) $availability->end_time),
            ])
            ->values();
    }

    // ── Private ───────────────────────────────────────────────────────────

    /** @return Collection<int, array{name: string, url: string, avatar_url: ?string, headline: ?string}> lightweight matches for the global search widget */
    public function search(string $term, int $limit = 5): Collection
    {
        $term = trim($term);

        if ($term === '') {
            return new Collection;
        }

        return $this->applyTextSearch($this->baseQuery(), $term)
            ->orderByRaw('user_profiles.is_featured DESC')
            ->orderBy('users.name')
            ->limit($limit)
            ->get()
            ->map(fn (User $instructor): array => [
                'name' => $instructor->name,
                'url' => route('instructors.show', $instructor),
                'avatar_url' => $instructor->profile?->avatarUrl,
                'headline' => $instructor->profile?->headline,
            ]);
    }

    private function applyTextSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $sub) use ($term): void {
            $sub->where('users.name', 'like', '%'.$term.'%')
                ->orWhere('user_profiles.bio', 'like', '%'.$term.'%')
                ->orWhere('user_profiles.headline', 'like', '%'.$term.'%')
                ->orWhere('user_profiles.short_bio', 'like', '%'.$term.'%');
        });
    }

    private function baseQuery(): Builder
    {
        return User::query()
            ->join('user_profiles', 'users.id', '=', 'user_profiles.user_id')
            ->whereNull('user_profiles.deleted_at')
            ->where('users.status', 'active')
            ->where('user_profiles.profile_visibility', 'public')
            ->whereIn('user_profiles.instructor_status', InstructorStatus::bookableValues())
            ->whereHas('roles', fn (Builder $q) => $q->where('name', 'instructor'))
            ->with($this->cardRelations())
            ->select('users.*')
            ->addSelect('user_profiles.language as profile_language');
    }

    private function cardRelations(): array
    {
        return [
            'profile.media',
            'profile.country',
            'profile.state',
            'media',
            'teacherSubjects',
            'teacherAvailability',
            'experiences',
        ];
    }

    private function detailRelations(): array
    {
        return [
            ...$this->cardRelations(),
            'educations',
        ];
    }

    private function availableSubjects(): Collection
    {
        return TeacherSubject::query()
            ->whereHas('teacher', fn (Builder $query) => $query
                ->where('users.status', 'active')
                ->whereHas('roles', fn (Builder $roleQuery) => $roleQuery->where('name', 'instructor'))
                ->whereHas('profile', fn (Builder $profileQuery) => $profileQuery
                    ->whereNull('deleted_at')
                    ->where('profile_visibility', 'public')
                    ->whereIn('instructor_status', InstructorStatus::bookableValues())))
            ->select('subject')
            ->distinct()
            ->orderBy('subject')
            ->pluck('subject')
            ->map(fn (string $subject): array => [
                'value' => $subject,
                'label' => $this->formatSubject($subject),
            ])
            ->values();
    }

    private function availableLanguages(): Collection
    {
        return $this->baseQuery()
            ->whereNotNull('user_profiles.language')
            ->pluck('profile_language')
            ->flatMap(fn (?string $language): array => preg_split('/[,|]/', (string) $language) ?: [])
            ->map(fn (string $language): string => trim($language))
            ->filter()
            ->unique()
            ->sort()
            ->map(fn (string $language): array => [
                'value' => $language,
                'label' => $language,
            ])
            ->values();
    }

    private function formatSubject(string $subject): string
    {
        return Str::headline(str_replace(['_', '-'], ' ', $subject));
    }

    private function formatGradeRange(?int $from, ?int $to): ?string
    {
        return match (true) {
            $from === null && $to === null => null,
            $from !== null && $to !== null && $from === $to => "Grade {$from}",
            $from !== null && $to !== null => "Grades {$from}-{$to}",
            $from !== null => "Grade {$from}+",
            default => "Up to grade {$to}",
        };
    }

    private function formatTimeRange(string $start, string $end): string
    {
        return Str::of($start)->substr(0, 5)->toString().' - '.Str::of($end)->substr(0, 5)->toString();
    }
}
