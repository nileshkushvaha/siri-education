<?php

declare(strict_types=1);

namespace App\Livewire\Frontend\Instructor;

use App\Enums\EducationLevel;
use App\Enums\EmploymentType;
use App\Enums\InstructorStatus;
use App\Models\AcademicLevel;
use App\Models\Country;
use App\Models\Language;
use App\Models\SkillLevel;
use App\Models\Subject;
use App\Services\Instructor\InstructorOnboardingService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

final class OnboardingWizard extends Component
{
    use WithFileUploads;

    public int $step = 1;

    public array $progress = ['status' => null, 'missing' => [], 'percentage' => 0, 'next_action' => 'complete_required_items'];

    public array $profile = [
        'headline' => '',
        'bio' => '',
        'teaching_experience_summary' => '',
        'teaching_philosophy' => '',
    ];

    /** @var list<string> */
    public array $subjectIds = [];

    /** @var list<string> */
    public array $academicLevelIds = [];

    /** @var list<string> */
    public array $skillLevelIds = [];

    /** @var list<int|string> */
    public array $teachingLanguageIds = [];

    public ?int $countryId = null;

    public ?string $timezone = null;

    public array $educationForm = [
        'id' => null,
        'institution_name' => '',
        'degree' => '',
        'field_of_study' => '',
        'education_level' => 'bachelor',
        'description' => '',
        'start_date' => '',
        'end_date' => '',
        'is_current' => false,
    ];

    public array $experienceForm = [
        'id' => null,
        'organization_name' => '',
        'designation' => '',
        'employment_type' => 'full_time',
        'industry' => '',
        'location' => '',
        'description' => '',
        'skills' => '',
        'start_date' => '',
        'end_date' => '',
        'is_current' => false,
    ];

    public ?TemporaryUploadedFile $profilePhoto = null;

    public ?TemporaryUploadedFile $introductionVideo = null;

    public ?TemporaryUploadedFile $governmentId = null;

    public ?TemporaryUploadedFile $addressProof = null;

    public ?TemporaryUploadedFile $educationCertificate = null;

    public ?TemporaryUploadedFile $teachingCertificate = null;

    public ?TemporaryUploadedFile $resume = null;

    /** @var list<array{id: string, name: string}> */
    public array $subjects = [];

    /** @var list<array{id: string, name: string}> */
    public array $academicLevels = [];

    /** @var list<array{id: string, name: string}> */
    public array $skillLevels = [];

    /** @var list<array{id: int, name: string}> */
    public array $languages = [];

    /** @var list<array{id: int, name: string}> */
    public array $countries = [];

    /** @var list<string> */
    public array $timezones = [];

    /** @var list<array{value: string, label: string}> */
    public array $educationLevels = [];

    /** @var list<array{value: string, label: string}> */
    public array $employmentTypes = [];

    public function mount(): void
    {
        $this->loadReferenceData();
        $this->refreshState();
    }

    public function start(): void
    {
        $onboarding = app(InstructorOnboardingService::class);

        $onboarding->start(auth()->user());
        $this->refreshState();
        $this->step = 2;
        session()->flash('success', 'Instructor onboarding started.');
    }

    public function saveProfile(): void
    {
        $onboarding = app(InstructorOnboardingService::class);

        $data = $this->validate([
            'profile.headline' => ['required', 'string', 'max:255'],
            'profile.bio' => ['required', 'string', 'max:2000'],
            'profile.teaching_experience_summary' => ['required', 'string', 'max:2000'],
            'profile.teaching_philosophy' => ['required', 'string', 'max:2000'],
            'profilePhoto' => ['nullable', 'image', 'max:4096'],
            'introductionVideo' => ['nullable', 'file', 'mimes:mp4,webm,quicktime', 'max:51200'],
        ]);

        $onboarding->updateProfile(auth()->user(), $data['profile']);

        if ($this->profilePhoto instanceof TemporaryUploadedFile) {
            $onboarding->uploadMedia(auth()->user(), 'avatar', $this->profilePhoto);
            $this->profilePhoto = null;
        }

        if ($this->introductionVideo instanceof TemporaryUploadedFile) {
            $onboarding->uploadMedia(auth()->user(), 'introduction_video', $this->introductionVideo);
            $this->introductionVideo = null;
        }

        $this->refreshState();
        session()->flash('success', 'Professional profile saved.');
    }

    public function savePreferences(): void
    {
        $onboarding = app(InstructorOnboardingService::class);

        $data = $this->validate([
            'subjectIds' => ['required', 'array', 'min:1'],
            'subjectIds.*' => ['string', Rule::in(array_column($this->subjects, 'id'))],
            'academicLevelIds' => ['required', 'array', 'min:1'],
            'academicLevelIds.*' => ['string', Rule::in(array_column($this->academicLevels, 'id'))],
            'skillLevelIds' => ['array'],
            'skillLevelIds.*' => ['string', Rule::in(array_column($this->skillLevels, 'id'))],
            'teachingLanguageIds' => ['required', 'array', 'min:1'],
            'teachingLanguageIds.*' => [Rule::in(array_map('strval', array_column($this->languages, 'id')))],
            'countryId' => ['nullable', 'integer', Rule::in(array_column($this->countries, 'id'))],
            'timezone' => ['nullable', 'string', 'timezone:all'],
        ]);

        $onboarding->updateProfile(auth()->user(), [
            'subject_ids' => $data['subjectIds'],
            'academic_level_ids' => $data['academicLevelIds'],
            'skill_level_ids' => $data['skillLevelIds'] ?? [],
            'teaching_language_ids' => $data['teachingLanguageIds'],
            'country_id' => $data['countryId'],
            'timezone' => $data['timezone'],
        ]);

        $this->refreshState();
        session()->flash('success', 'Teaching preferences saved.');
    }

    public function saveEducation(): void
    {
        $onboarding = app(InstructorOnboardingService::class);

        $data = $this->validate([
            'educationForm.id' => ['nullable', 'integer'],
            'educationForm.institution_name' => ['required', 'string', 'max:255'],
            'educationForm.degree' => ['required', 'string', 'max:255'],
            'educationForm.field_of_study' => ['nullable', 'string', 'max:255'],
            'educationForm.education_level' => ['required', Rule::in(array_column($this->educationLevels, 'value'))],
            'educationForm.description' => ['nullable', 'string', 'max:1000'],
            'educationForm.start_date' => ['required', 'date'],
            'educationForm.end_date' => ['nullable', 'date', 'after_or_equal:educationForm.start_date'],
            'educationForm.is_current' => ['boolean'],
        ]);

        $onboarding->upsertEducation(auth()->user(), $data['educationForm']['id'], $data['educationForm']);

        $this->resetEducationForm();
        $this->refreshState();
        session()->flash('success', 'Education saved.');
    }

    public function editEducation(int $educationId): void
    {
        $education = auth()->user()->educations()->whereKey($educationId)->firstOrFail();

        $this->educationForm = [
            'id' => $education->id,
            'institution_name' => $education->institution_name,
            'degree' => $education->degree,
            'field_of_study' => $education->field_of_study,
            'education_level' => $education->education_level?->value,
            'description' => $education->description,
            'start_date' => $education->start_date?->format('Y-m-d'),
            'end_date' => $education->end_date?->format('Y-m-d'),
            'is_current' => (bool) $education->is_current,
        ];
    }

    public function deleteEducation(int $educationId): void
    {
        $onboarding = app(InstructorOnboardingService::class);

        $onboarding->deleteEducation(auth()->user(), $educationId);
        $this->refreshState();
    }

    public function saveExperience(): void
    {
        $onboarding = app(InstructorOnboardingService::class);

        $data = $this->validate([
            'experienceForm.id' => ['nullable', 'integer'],
            'experienceForm.organization_name' => ['required', 'string', 'max:255'],
            'experienceForm.designation' => ['required', 'string', 'max:255'],
            'experienceForm.employment_type' => ['required', Rule::in(array_column($this->employmentTypes, 'value'))],
            'experienceForm.industry' => ['nullable', 'string', 'max:255'],
            'experienceForm.location' => ['nullable', 'string', 'max:255'],
            'experienceForm.description' => ['nullable', 'string', 'max:1000'],
            'experienceForm.skills' => ['nullable', 'string', 'max:500'],
            'experienceForm.start_date' => ['required', 'date'],
            'experienceForm.end_date' => ['nullable', 'date', 'after_or_equal:experienceForm.start_date'],
            'experienceForm.is_current' => ['boolean'],
        ]);

        $onboarding->upsertExperience(auth()->user(), $data['experienceForm']['id'], $data['experienceForm']);

        $this->resetExperienceForm();
        $this->refreshState();
        session()->flash('success', 'Experience saved.');
    }

    public function editExperience(int $experienceId): void
    {
        $experience = auth()->user()->experiences()->whereKey($experienceId)->firstOrFail();

        $this->experienceForm = [
            'id' => $experience->id,
            'organization_name' => $experience->organization_name,
            'designation' => $experience->designation,
            'employment_type' => $experience->employment_type?->value,
            'industry' => $experience->industry,
            'location' => $experience->location,
            'description' => $experience->description,
            'skills' => implode(', ', $experience->skills ?? []),
            'start_date' => $experience->start_date?->format('Y-m-d'),
            'end_date' => $experience->end_date?->format('Y-m-d'),
            'is_current' => (bool) $experience->is_current,
        ];
    }

    public function deleteExperience(int $experienceId): void
    {
        $onboarding = app(InstructorOnboardingService::class);

        $onboarding->deleteExperience(auth()->user(), $experienceId);
        $this->refreshState();
    }

    public function uploadDocument(string $collection): void
    {
        $onboarding = app(InstructorOnboardingService::class);

        $property = $this->uploadPropertyFor($collection);

        $this->validate([
            $property => $collection === 'introduction_video'
                ? ['required', 'file', 'mimes:mp4,webm,quicktime', 'max:51200']
                : ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:4096'],
        ]);

        $file = $this->{$property};
        if ($file instanceof TemporaryUploadedFile) {
            $onboarding->uploadMedia(auth()->user(), $collection, $file);
        }

        $this->{$property} = null;
        $this->refreshState();
        session()->flash('success', 'Document uploaded.');
    }

    public function submit(): void
    {
        $onboarding = app(InstructorOnboardingService::class);

        $onboarding->submit(auth()->user());
        $this->refreshState();
        $this->step = 7;
        session()->flash('success', 'Instructor application submitted for review.');
    }

    public function render(): View
    {
        return view('livewire.frontend.instructor.onboarding-wizard', [
            'user' => auth()->user()->loadMissing(['profile.media', 'teacherSubjects.subjectMaster', 'educations', 'experiences']),
            'editable' => $this->isEditable(),
            'documents' => $this->documents(),
        ]);
    }

    private function refreshState(): void
    {
        $user = auth()->user()->fresh(['profile.media', 'teacherSubjects.subjectMaster', 'educations', 'experiences']);
        $profile = $user->profile;

        $this->progress = app(InstructorOnboardingService::class)->progress($user);
        $this->profile = [
            'headline' => (string) $profile?->headline,
            'bio' => (string) $profile?->bio,
            'teaching_experience_summary' => (string) $profile?->instructor_teaching_experience_summary,
            'teaching_philosophy' => (string) $profile?->instructor_teaching_philosophy,
        ];
        $this->subjectIds = $user->teacherSubjects->pluck('subject_id')->filter()->map(fn ($id): string => (string) $id)->values()->all();
        $this->academicLevelIds = array_values(array_filter($profile?->instructor_academic_level_ids ?? []));
        $this->skillLevelIds = array_values(array_filter($profile?->instructor_skill_level_ids ?? []));
        $this->teachingLanguageIds = array_map('strval', array_values(array_filter($profile?->instructor_teaching_language_ids ?? [])));
        $this->countryId = $profile?->country_id;
        $this->timezone = $profile?->timezone;
    }

    private function loadReferenceData(): void
    {
        $this->subjects = Subject::query()->availableForAssignment()->orderBy('name')->get(['id', 'name'])
            ->map(fn (Subject $subject): array => ['id' => (string) $subject->id, 'name' => $subject->name])
            ->all();
        $this->academicLevels = AcademicLevel::query()->availableForAssignment()->orderBy('display_order')->orderBy('name')->get(['id', 'name'])
            ->map(fn (AcademicLevel $level): array => ['id' => (string) $level->id, 'name' => $level->name])
            ->all();
        $this->skillLevels = SkillLevel::query()->active()->orderBy('display_order')->orderBy('name')->get(['id', 'name'])
            ->map(fn (SkillLevel $level): array => ['id' => (string) $level->id, 'name' => $level->name])
            ->all();
        $this->languages = Language::query()->active()->orderBy('name')->get(['id', 'name'])
            ->map(fn (Language $language): array => ['id' => $language->id, 'name' => $language->name])
            ->all();
        $this->countries = Country::query()->active()->orderBy('name')->get(['id', 'name'])
            ->map(fn (Country $country): array => ['id' => $country->id, 'name' => $country->name])
            ->all();
        $this->timezones = \DateTimeZone::listIdentifiers();
        $this->educationLevels = array_map(fn (EducationLevel $level): array => ['value' => $level->value, 'label' => $level->label()], EducationLevel::cases());
        $this->employmentTypes = array_map(fn (EmploymentType $type): array => ['value' => $type->value, 'label' => $type->label()], EmploymentType::cases());
    }

    private function documents(): array
    {
        $profile = auth()->user()->profile;

        return collect(InstructorOnboardingService::REQUIRED_DOCUMENT_COLLECTIONS)
            ->map(fn (string $collection): array => [
                'collection' => $collection,
                'label' => str($collection)->replace('_', ' ')->title()->toString(),
                'uploaded' => (bool) $profile?->hasMedia($collection),
            ])
            ->values()
            ->all();
    }

    private function isEditable(): bool
    {
        return in_array($this->progress['status'], [null, InstructorStatus::Draft, InstructorStatus::DocumentsPending], true);
    }

    private function uploadPropertyFor(string $collection): string
    {
        return match ($collection) {
            'government_id' => 'governmentId',
            'address_proof' => 'addressProof',
            'education_certificate' => 'educationCertificate',
            'teaching_certificate' => 'teachingCertificate',
            'resume' => 'resume',
            'introduction_video' => 'introductionVideo',
            default => 'governmentId',
        };
    }

    private function resetEducationForm(): void
    {
        $this->educationForm = [
            'id' => null,
            'institution_name' => '',
            'degree' => '',
            'field_of_study' => '',
            'education_level' => 'bachelor',
            'description' => '',
            'start_date' => '',
            'end_date' => '',
            'is_current' => false,
        ];
    }

    private function resetExperienceForm(): void
    {
        $this->experienceForm = [
            'id' => null,
            'organization_name' => '',
            'designation' => '',
            'employment_type' => 'full_time',
            'industry' => '',
            'location' => '',
            'description' => '',
            'skills' => '',
            'start_date' => '',
            'end_date' => '',
            'is_current' => false,
        ];
    }
}
