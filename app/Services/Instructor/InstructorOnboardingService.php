<?php

declare(strict_types=1);

namespace App\Services\Instructor;

use App\Enums\InstructorStatus;
use App\Models\AcademicLevel;
use App\Models\Language;
use App\Models\SkillLevel;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserEducation;
use App\Models\UserExperience;
use App\Models\UserProfile;
use App\Services\AuditTrailService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Models\Role;

final class InstructorOnboardingService
{
    public const REVIEW_PERMISSION = 'instructor.applications.review';

    public const REQUIRED_DOCUMENT_COLLECTIONS = [
        'government_id',
        'address_proof',
        'education_certificate',
        'teaching_certificate',
        'resume',
    ];

    public const OPTIONAL_DOCUMENT_COLLECTIONS = [
        'introduction_video',
    ];

    public const PROFILE_MEDIA_COLLECTIONS = [
        'avatar',
        'introduction_video',
    ];

    public function __construct(
        private readonly AuditTrailService $auditTrail,
    ) {}

    public function start(User $user): UserProfile
    {
        $this->ensureEmailVerified($user);

        return DB::transaction(function () use ($user): UserProfile {
            $profile = $user->profile()->firstOrCreate(['user_id' => $user->id]);
            $started = $profile->instructor_status === null;

            if ($started) {
                $profile->forceFill([
                    'instructor_status' => InstructorStatus::Draft,
                    'instructor_application_started_at' => now(),
                ])->save();
            }

            Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
            if (! $user->hasRole('instructor')) {
                $user->assignRole('instructor');
            }

            if ($started) {
                $this->auditTrail->logUser(
                    $user,
                    'instructor',
                    'application_started',
                    'Instructor application started',
                    $user,
                    ['instructor_status' => InstructorStatus::Draft->value],
                );
            }

            return $profile->fresh();
        });
    }

    public function updateProfile(User $user, array $data): UserProfile
    {
        return DB::transaction(function () use ($user, $data): UserProfile {
            $profile = $this->start($user);
            $this->ensureEditable($profile);

            $profile->update([
                'headline' => $data['headline'] ?? $profile->headline,
                'bio' => $data['bio'] ?? $profile->bio,
                'instructor_teaching_experience_summary' => $data['teaching_experience_summary'] ?? $profile->instructor_teaching_experience_summary,
                'instructor_teaching_philosophy' => $data['teaching_philosophy'] ?? $profile->instructor_teaching_philosophy,
                'instructor_academic_level_ids' => $this->validAcademicLevelIds($data['academic_level_ids'] ?? $profile->instructor_academic_level_ids ?? []),
                'instructor_skill_level_ids' => $this->validSkillLevelIds($data['skill_level_ids'] ?? $profile->instructor_skill_level_ids ?? []),
                'instructor_teaching_language_ids' => $this->validLanguageIds($data['teaching_language_ids'] ?? $profile->instructor_teaching_language_ids ?? []),
                'country_id' => $data['country_id'] ?? $profile->country_id,
                'timezone' => $data['timezone'] ?? $profile->timezone,
            ]);

            if (array_key_exists('subject_ids', $data)) {
                $this->syncSubjects($user, (array) $data['subject_ids']);
            }

            $this->auditTrail->logUser($user, 'instructor', 'application_updated', 'Instructor application updated', $user);

            return $profile->fresh();
        });
    }

    public function uploadMedia(User $user, string $collection, UploadedFile $file): UserProfile
    {
        return DB::transaction(function () use ($user, $collection, $file): UserProfile {
            $profile = $this->start($user);
            $this->ensureEditable($profile);
            $this->ensureKnownMediaCollection($collection);

            $profile->addMedia($file)->toMediaCollection($collection);

            $this->auditTrail->logUser(
                $user,
                'instructor',
                'application_media_updated',
                'Instructor application media updated',
                $user,
                ['collection' => $collection],
            );

            return $profile->fresh('media');
        });
    }

    public function upsertEducation(User $user, ?int $educationId, array $data): UserEducation
    {
        return DB::transaction(function () use ($user, $educationId, $data): UserEducation {
            $profile = $this->start($user);
            $this->ensureEditable($profile);

            $education = $educationId
                ? $user->educations()->whereKey($educationId)->firstOrFail()
                : new UserEducation(['user_id' => $user->id]);

            $education->fill([
                'institution_name' => $data['institution_name'],
                'degree' => $data['degree'],
                'field_of_study' => $data['field_of_study'] ?? null,
                'education_level' => $data['education_level'],
                'description' => $data['description'] ?? null,
                'start_date' => $data['start_date'],
                'end_date' => ($data['is_current'] ?? false) ? null : ($data['end_date'] ?? null),
                'is_current' => (bool) ($data['is_current'] ?? false),
                'status' => 'active',
            ]);
            $education->save();

            $this->auditTrail->logUser($user, 'instructor', 'application_education_updated', 'Instructor education updated', $user);

            return $education->fresh();
        });
    }

    public function deleteEducation(User $user, int $educationId): void
    {
        DB::transaction(function () use ($user, $educationId): void {
            $profile = $this->start($user);
            $this->ensureEditable($profile);

            $user->educations()->whereKey($educationId)->firstOrFail()->delete();

            $this->auditTrail->logUser($user, 'instructor', 'application_education_deleted', 'Instructor education deleted', $user);
        });
    }

    public function upsertExperience(User $user, ?int $experienceId, array $data): UserExperience
    {
        return DB::transaction(function () use ($user, $experienceId, $data): UserExperience {
            $profile = $this->start($user);
            $this->ensureEditable($profile);

            $experience = $experienceId
                ? $user->experiences()->whereKey($experienceId)->firstOrFail()
                : new UserExperience(['user_id' => $user->id]);

            $experience->fill([
                'organization_name' => $data['organization_name'],
                'designation' => $data['designation'],
                'employment_type' => $data['employment_type'],
                'industry' => $data['industry'] ?? null,
                'location' => $data['location'] ?? null,
                'description' => $data['description'] ?? null,
                'skills' => $this->skillsArray($data['skills'] ?? []),
                'start_date' => $data['start_date'],
                'end_date' => ($data['is_current'] ?? false) ? null : ($data['end_date'] ?? null),
                'is_current' => (bool) ($data['is_current'] ?? false),
                'status' => 'active',
            ]);
            $experience->save();

            $this->auditTrail->logUser($user, 'instructor', 'application_experience_updated', 'Instructor experience updated', $user);

            return $experience->fresh();
        });
    }

    public function deleteExperience(User $user, int $experienceId): void
    {
        DB::transaction(function () use ($user, $experienceId): void {
            $profile = $this->start($user);
            $this->ensureEditable($profile);

            $user->experiences()->whereKey($experienceId)->firstOrFail()->delete();

            $this->auditTrail->logUser($user, 'instructor', 'application_experience_deleted', 'Instructor experience deleted', $user);
        });
    }

    public function submit(User $user): UserProfile
    {
        $this->ensureEmailVerified($user);

        return DB::transaction(function () use ($user): UserProfile {
            $profile = $this->start($user);
            $this->ensureSubmittable($profile);

            $missing = $this->missingRequiredItems($user);

            if ($missing !== []) {
                throw ValidationException::withMessages([
                    'application' => 'Complete these items before submitting: '.implode(', ', $missing),
                ]);
            }

            $profile->update([
                'instructor_status' => InstructorStatus::Submitted,
                'instructor_application_submitted_at' => now(),
            ]);

            $this->auditTrail->logUser(
                $user,
                'instructor',
                'application_submitted',
                'Instructor application submitted',
                $user,
            );

            return $profile->fresh();
        });
    }

    public function markUnderReview(User $admin, User $instructor): UserProfile
    {
        $this->authorizeReview($admin);

        return $this->transitionByAdmin(
            $admin,
            $instructor,
            InstructorStatus::UnderReview,
            'application_under_review',
            'Instructor application marked under review',
        );
    }

    public function requestDocuments(User $admin, User $instructor, string $reason): UserProfile
    {
        $this->authorizeReview($admin);
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'A document request reason is required.']);
        }

        return DB::transaction(function () use ($admin, $instructor, $reason): UserProfile {
            $profile = $this->transitionByAdmin(
                $admin,
                $instructor,
                InstructorStatus::DocumentsPending,
                'documents_requested',
                'Additional instructor documents requested',
                ['reason' => $reason],
            );

            $profile->update(['instructor_documents_requested_reason' => $reason]);

            return $profile->fresh();
        });
    }

    public function approve(User $admin, User $instructor, ?string $reason = null): UserProfile
    {
        $this->authorizeReview($admin);

        return DB::transaction(function () use ($admin, $instructor, $reason): UserProfile {
            $profile = $this->transitionByAdmin(
                $admin,
                $instructor,
                InstructorStatus::Approved,
                'application_approved',
                'Instructor application approved',
                ['reason' => $reason],
            );

            $profile->update([
                'is_instructor_verified' => true,
                'instructor_review_reason' => $reason,
            ]);

            return $profile->fresh();
        });
    }

    public function reject(User $admin, User $instructor, string $reason): UserProfile
    {
        $this->authorizeReview($admin);
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'A rejection reason is required.']);
        }

        return DB::transaction(function () use ($admin, $instructor, $reason): UserProfile {
            $profile = $this->transitionByAdmin(
                $admin,
                $instructor,
                InstructorStatus::Rejected,
                'application_rejected',
                'Instructor application rejected',
                ['reason' => $reason],
            );

            $profile->update([
                'is_instructor_verified' => false,
                'instructor_review_reason' => $reason,
            ]);

            return $profile->fresh();
        });
    }

    public function progress(User $user): array
    {
        $missing = $this->missingRequiredItems($user);
        $total = 14;
        $status = $user->profile?->instructor_status;

        return [
            'status' => $status,
            'missing' => $missing,
            'percentage' => $total > 0 ? (int) round((($total - count($missing)) / $total) * 100) : 0,
            'next_action' => $missing === [] && $this->isSubmittableStatus($status)
                ? 'submit_application'
                : 'complete_required_items',
        ];
    }

    public function missingRequiredItems(User $user): array
    {
        $profile = $user->profile;

        $missing = [];

        if (! $profile) {
            return ['profile'];
        }

        foreach ([
            'professional headline' => $profile->headline,
            'biography' => $profile->bio,
            'teaching experience summary' => $profile->instructor_teaching_experience_summary,
            'teaching philosophy' => $profile->instructor_teaching_philosophy,
        ] as $label => $value) {
            if (blank($value)) {
                $missing[] = $label;
            }
        }

        if ($user->teacherSubjects()->whereNotNull('subject_id')->count() === 0) {
            $missing[] = 'subjects';
        }

        if (empty($profile->instructor_academic_level_ids)) {
            $missing[] = 'academic levels';
        }

        if (empty($profile->instructor_teaching_language_ids)) {
            $missing[] = 'teaching languages';
        }

        if ($user->educations()->active()->count() === 0) {
            $missing[] = 'education';
        }

        if ($user->experiences()->active()->count() === 0) {
            $missing[] = 'experience';
        }

        foreach (self::REQUIRED_DOCUMENT_COLLECTIONS as $collection) {
            if (! $profile->hasMedia($collection)) {
                $missing[] = str_replace('_', ' ', $collection);
            }
        }

        return $missing;
    }

    private function syncSubjects(User $user, array $subjectIds): void
    {
        $subjects = Subject::query()
            ->availableForAssignment()
            ->whereIn('id', array_values(array_filter($subjectIds)))
            ->get();

        $user->teacherSubjects()->whereNotNull('subject_id')->delete();

        $subjects->each(function (Subject $subject) use ($user): void {
            $user->teacherSubjects()->create([
                'subject_id' => $subject->id,
                'subject' => $subject->name,
            ]);
        });
    }

    private function validAcademicLevelIds(array $ids): array
    {
        return AcademicLevel::query()
            ->availableForAssignment()
            ->whereIn('id', array_values(array_filter($ids)))
            ->pluck('id')
            ->values()
            ->all();
    }

    private function validLanguageIds(array $ids): array
    {
        return Language::query()
            ->active()
            ->whereIn('id', array_values(array_filter($ids)))
            ->pluck('id')
            ->map(fn (int $id): string => (string) $id)
            ->values()
            ->all();
    }

    private function validSkillLevelIds(array $ids): array
    {
        return SkillLevel::query()
            ->active()
            ->whereIn('id', array_values(array_filter($ids)))
            ->pluck('id')
            ->values()
            ->all();
    }

    private function transitionByAdmin(
        User $admin,
        User $instructor,
        InstructorStatus $status,
        string $event,
        string $description,
        array $properties = [],
    ): UserProfile {
        $profile = $instructor->profile()->firstOrCreate(['user_id' => $instructor->id]);
        $previousStatus = $profile->instructor_status;

        $profile->update([
            'instructor_status' => $status,
            'instructor_reviewed_at' => now(),
            'instructor_reviewed_by' => $admin->id,
        ]);

        $this->auditTrail->logUser($admin, 'instructor', $event, $description, $instructor, [
            'previous_status' => $previousStatus?->value,
            'new_status' => $status->value,
            ...array_filter($properties, fn ($value): bool => filled($value)),
        ]);

        return $profile->fresh();
    }

    private function authorizeReview(User $admin): void
    {
        if (! $this->canReviewApplications($admin)) {
            throw new AuthorizationException('You are not authorized to review instructor applications.');
        }
    }

    private function ensureEmailVerified(User $user): void
    {
        if (! $user->hasVerifiedEmail()) {
            throw ValidationException::withMessages([
                'email' => 'Please verify your email before starting instructor onboarding.',
            ]);
        }
    }

    private function ensureSubmittable(UserProfile $profile): void
    {
        if ($this->isSubmittableStatus($profile->instructor_status)) {
            return;
        }

        throw ValidationException::withMessages([
            'application' => 'This instructor application has already been submitted or reviewed.',
        ]);
    }

    private function isSubmittableStatus(?InstructorStatus $status): bool
    {
        return in_array($status, [null, InstructorStatus::Draft, InstructorStatus::DocumentsPending], true);
    }

    private function ensureEditable(UserProfile $profile): void
    {
        if ($this->isSubmittableStatus($profile->instructor_status)) {
            return;
        }

        throw ValidationException::withMessages([
            'application' => 'This instructor application cannot be edited in its current status.',
        ]);
    }

    private function ensureKnownMediaCollection(string $collection): void
    {
        if (in_array($collection, [...self::REQUIRED_DOCUMENT_COLLECTIONS, ...self::OPTIONAL_DOCUMENT_COLLECTIONS, ...self::PROFILE_MEDIA_COLLECTIONS], true)) {
            return;
        }

        throw ValidationException::withMessages(['document' => 'Unsupported instructor onboarding upload.']);
    }

    private function skillsArray(array|string $skills): array
    {
        if (is_string($skills)) {
            $skills = preg_split('/[,|]/', $skills) ?: [];
        }

        return array_values(array_filter(array_map(
            fn (string $skill): string => trim($skill),
            array_map('strval', $skills),
        )));
    }

    public function canReviewApplications(User $admin): bool
    {
        return $this->hasPermission($admin, self::REVIEW_PERMISSION)
            || $this->hasPermission($admin, 'Update:User');
    }

    private function hasPermission(User $user, string $permission): bool
    {
        try {
            return $user->can($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
