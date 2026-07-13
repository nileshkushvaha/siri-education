<?php

declare(strict_types=1);

namespace App\Services\Instructor;

use App\Enums\AcademicStatus;
use App\Enums\InstructorStatus;
use App\Models\AcademicLevel;
use App\Models\Country;
use App\Models\InstructorSubjectTopic;
use App\Models\Language;
use App\Models\Subject;
use App\Models\SubjectTopic;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Reviews\Contracts\InstructorRatingAggregateServiceInterface;
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
        private readonly InstructorRatingAggregateServiceInterface $ratings,
    ) {}

    public function listing(Request $request): LengthAwarePaginator
    {
        $query = $this->baseQuery();

        if ($q = $request->string('q')->trim()->toString()) {
            $this->applyTextSearch($query, $q);
        }

        if ($subject = $request->string('subject')->trim()->toString()) {
            $this->applySubjectFilter($query, $subject);
        }

        if ($topic = $request->string('topic')->trim()->toString()) {
            $this->applyTopicFilter($query, $topic);
        }

        if ($academicLevel = $request->string('academic_level')->trim()->toString()) {
            $this->applyAcademicLevelFilter($query, $academicLevel);
        }

        if ($language = $request->string('language')->trim()->toString()) {
            $this->applyLanguageFilter($query, $language);
        }

        if ($country = $request->integer('country')) {
            $query->where('user_profiles.country_id', $country);
        }

        if ($timezone = $request->string('timezone')->trim()->toString()) {
            $query->where('user_profiles.timezone', $timezone);
        }

        if ($request->boolean('available')) {
            $query->whereHas('teacherAvailability', fn (Builder $availabilityQuery) => $availabilityQuery->active());
        }

        $sort = $request->input('sort', 'featured');

        match ($sort) {
            'name' => $query->orderBy('users.name'),
            'newest' => $query->orderByDesc('users.created_at'),
            default => $query->orderByRaw('COALESCE(user_profiles.is_featured, 0) DESC')
                ->orderByRaw('COALESCE(user_profiles.featured_order, 999999)')
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
            'topics' => $this->availableTopics(),
            'academic_levels' => $this->availableAcademicLevels(),
            'languages' => $this->availableLanguages(),
            'countries' => $this->availableCountries(),
            'timezones' => $this->availableTimezones(),
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

    public function related(User $instructor, int $limit = 3): Collection
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
            'avg_rating' => $this->ratingsFor($instructor)['average'],
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
        $topics = $this->topicsFor($instructor);
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
            'topics',
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
        $academicLevels = $this->academicLevelsFor($instructor)->take(3);
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
            'academic_levels' => $academicLevels,
            'ratings' => $ratings,
            'availability_preview' => $availability,
            'years_experience' => $this->experienceService->yearsOfExperience($instructor),
            'country' => $profile?->country?->name,
            'timezone' => $profile?->timezone,
        ];
    }

    /**
     * Prefers the linked Subject master's name/slug when a teacher_subjects
     * row has been reconciled to one (subject_id set); falls back to the
     * legacy free-text value for rows that haven't been (or couldn't be)
     * matched. See docs/architecture/subject-teacher-subject-reconciliation.md.
     */
    public function subjectsFor(User $instructor): Collection
    {
        $instructor->loadMissing('teacherSubjects.subjectMaster');

        return $instructor->teacherSubjects
            ->sortBy('subject')
            ->map(fn ($subject): array => [
                'name' => $subject->subjectMaster?->name ?? $this->formatSubject((string) $subject->subject),
                'slug' => $subject->subjectMaster?->slug ?? (string) $subject->subject,
                'booking_value' => (string) $subject->subject,
                'grade_range' => $this->formatGradeRange($subject->grade_from, $subject->grade_to),
            ])
            ->values();
    }

    /** Active, admin-approved topic coverage for public display — never pricing data. */
    public function topicsFor(User $instructor): Collection
    {
        $instructor->loadMissing('instructorSubjectTopics.topic.subject');

        return $instructor->instructorSubjectTopics
            ->filter(fn (InstructorSubjectTopic $coverage): bool => $coverage->is_active
                && $coverage->approved_at !== null
                && $coverage->topic?->status === AcademicStatus::Active)
            ->map(fn (InstructorSubjectTopic $coverage): array => [
                'name' => (string) $coverage->topic->name,
                'slug' => (string) $coverage->topic->slug,
                'subject' => (string) $coverage->topic->subject?->name,
            ])
            ->unique('slug')
            ->sortBy('name')
            ->values();
    }

    public function languagesFor(User $instructor): Collection
    {
        $profile = $instructor->profile;
        $languageIds = array_values(array_filter($profile?->instructor_teaching_language_ids ?? []));
        $languages = $languageIds === []
            ? new Collection
            : Language::query()
                ->whereIn('id', $languageIds)
                ->orderBy('name')
                ->pluck('name');

        if ($languages->isNotEmpty()) {
            return $languages->values();
        }

        return collect();
    }

    public function academicLevelsFor(User $instructor): Collection
    {
        $levelIds = array_values(array_filter($instructor->profile?->instructor_academic_level_ids ?? []));

        if ($levelIds === []) {
            return new Collection;
        }

        return AcademicLevel::query()
            ->whereIn('id', $levelIds)
            ->orderBy('display_order')
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(fn (AcademicLevel $level): array => [
                'id' => $level->id,
                'name' => $level->name,
                'slug' => $level->slug,
            ])
            ->values();
    }

    /** Delegates to Phase 17K's durable aggregate — never recalculated from review rows here. */
    public function ratingsFor(User $instructor): array
    {
        $summary = $this->ratings->summaryFor($instructor->id);

        return [
            'average' => $summary->averageRating,
            'count' => $summary->reviewCount,
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
                ->orWhere('user_profiles.short_bio', 'like', '%'.$term.'%')
                ->orWhereHas('teacherSubjects.subjectMaster', fn (Builder $subjectQuery) => $subjectQuery->where('name', 'like', '%'.$term.'%'))
                ->orWhereHas('teacherSubjects', fn (Builder $subjectQuery) => $subjectQuery->where('subject', 'like', '%'.$term.'%'));
        });
    }

    private function applySubjectFilter(Builder $query, string $subject): void
    {
        $subjectMaster = Subject::query()
            ->availableForAssignment()
            ->where(fn (Builder $subjectQuery) => $subjectQuery
                ->whereKey($subject)
                ->orWhere('slug', $subject))
            ->first();

        if ($subjectMaster) {
            $query->whereHas('teacherSubjects', fn (Builder $subjectQuery) => $subjectQuery
                ->where('subject_id', $subjectMaster->id)
                ->orWhere('subject', $subjectMaster->name));

            return;
        }

        $query->whereHas('teacherSubjects', fn (Builder $subjectQuery) => $subjectQuery->where('subject', $subject));
    }

    /** Only instructors with active, admin-approved coverage for the topic (Phase 12.5). */
    private function applyTopicFilter(Builder $query, string $topic): void
    {
        $topicMaster = SubjectTopic::query()
            ->active()
            ->where(fn (Builder $topicQuery) => $topicQuery
                ->whereKey($topic)
                ->orWhere('slug', $topic))
            ->first();

        if (! $topicMaster) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereHas('instructorSubjectTopics', fn (Builder $coverageQuery) => $coverageQuery
            ->where('subject_topic_id', $topicMaster->id)
            ->bookable());
    }

    private function applyAcademicLevelFilter(Builder $query, string $academicLevel): void
    {
        $level = AcademicLevel::query()
            ->availableForAssignment()
            ->where(fn (Builder $levelQuery) => $levelQuery
                ->whereKey($academicLevel)
                ->orWhere('slug', $academicLevel))
            ->first();

        if (! $level) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereJsonContains('user_profiles.instructor_academic_level_ids', $level->id);
    }

    private function applyLanguageFilter(Builder $query, string $language): void
    {
        $languageMaster = Language::query()
            ->active()
            ->where(fn (Builder $languageQuery) => $languageQuery
                ->whereKey($language)
                ->orWhere('code', $language)
                ->orWhere('name', $language))
            ->first();

        if ($languageMaster) {
            $query->whereJsonContains('user_profiles.instructor_teaching_language_ids', (string) $languageMaster->id);

            return;
        }

        $query->whereRaw('1 = 0');
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
            ->select('users.*');
    }

    private function cardRelations(): array
    {
        return [
            'profile.media',
            'profile.country',
            'profile.state',
            'media',
            'teacherSubjects.subjectMaster',
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
        $linked = Subject::query()
            ->availableForAssignment()
            ->whereHas('teacherSubjects.teacher', fn (Builder $query) => $query
                ->where('users.status', 'active')
                ->whereHas('roles', fn (Builder $roleQuery) => $roleQuery->where('name', 'instructor'))
                ->whereHas('profile', fn (Builder $profileQuery) => $profileQuery
                    ->whereNull('deleted_at')
                    ->where('profile_visibility', 'public')
                    ->whereIn('instructor_status', InstructorStatus::bookableValues())))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Subject $subject): array => [
                'value' => $subject->id,
                'label' => $subject->name,
            ])
            ->toBase();

        $legacy = TeacherSubject::query()
            ->whereNull('subject_id')
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
                'label' => $this->formatSubject($subject).' (legacy)',
            ])
            ->values();

        return $linked
            ->merge($legacy)
            ->unique('value')
            ->values();
    }

    /** Active topics with at least one bookable instructor holding active, approved coverage. */
    private function availableTopics(): Collection
    {
        return SubjectTopic::query()
            ->active()
            ->whereHas('subject', fn (Builder $subjectQuery) => $subjectQuery->availableForAssignment())
            ->whereHas('instructorCoverage', fn (Builder $coverageQuery) => $coverageQuery
                ->bookable()
                ->whereHas('teacher', fn (Builder $teacherQuery) => $teacherQuery
                    ->where('users.status', 'active')
                    ->whereHas('roles', fn (Builder $roleQuery) => $roleQuery->where('name', 'instructor'))
                    ->whereHas('profile', fn (Builder $profileQuery) => $profileQuery
                        ->whereNull('deleted_at')
                        ->where('profile_visibility', 'public')
                        ->whereIn('instructor_status', InstructorStatus::bookableValues()))))
            ->with('subject:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'subject_id'])
            ->map(fn (SubjectTopic $topic): array => [
                'value' => $topic->id,
                'label' => sprintf('%s — %s', $topic->subject?->name, $topic->name),
            ])
            ->values();
    }

    private function availableAcademicLevels(): Collection
    {
        return AcademicLevel::query()
            ->availableForAssignment()
            ->orderBy('display_order')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (AcademicLevel $level): array => [
                'value' => $level->id,
                'label' => $level->name,
            ])
            ->values();
    }

    private function availableLanguages(): Collection
    {
        $languageIds = UserProfile::query()
            ->where('profile_visibility', 'public')
            ->whereIn('instructor_status', InstructorStatus::bookableValues())
            ->whereNotNull('instructor_teaching_language_ids')
            ->whereHas('user', fn (Builder $userQuery) => $userQuery
                ->where('users.status', 'active')
                ->whereHas('roles', fn (Builder $roleQuery) => $roleQuery->where('name', 'instructor')))
            ->pluck('instructor_teaching_language_ids')
            ->flatMap(fn (mixed $ids): array => $this->normalizeJsonIdList($ids))
            ->filter()
            ->unique()
            ->values();

        if ($languageIds->isEmpty()) {
            return collect();
        }

        return Language::query()
            ->active()
            ->whereIn('id', $languageIds->all())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Language $language): array => [
                'value' => (string) $language->id,
                'label' => $language->name,
            ])
            ->values();
    }

    private function availableCountries(): Collection
    {
        return Country::query()
            ->active()
            ->whereHas('userProfiles', fn (Builder $profileQuery) => $profileQuery
                ->where('profile_visibility', 'public')
                ->whereIn('instructor_status', InstructorStatus::bookableValues())
                ->whereHas('user', fn (Builder $userQuery) => $userQuery
                    ->where('users.status', 'active')
                    ->whereHas('roles', fn (Builder $roleQuery) => $roleQuery->where('name', 'instructor'))))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Country $country): array => [
                'value' => $country->id,
                'label' => $country->name,
            ])
            ->values();
    }

    private function availableTimezones(): Collection
    {
        return $this->baseQuery()
            ->whereNotNull('user_profiles.timezone')
            ->select('user_profiles.timezone as profile_timezone')
            ->pluck('profile_timezone')
            ->filter()
            ->unique()
            ->sort()
            ->map(fn (string $timezone): array => [
                'value' => $timezone,
                'label' => $timezone,
            ])
            ->values();
    }

    private function formatSubject(string $subject): string
    {
        return Str::headline(str_replace(['_', '-'], ' ', $subject));
    }

    private function normalizeJsonIdList(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
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
