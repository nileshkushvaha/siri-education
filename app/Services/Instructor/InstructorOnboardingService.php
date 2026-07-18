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

    // Phase 23D — dedicated lifecycle-operation permissions. Deliberately
    // NOT given the Update:User compatibility fallback REVIEW_PERMISSION
    // has: that fallback exists for a pre-existing rollout, these are new.
    public const ACTIVATE_PERMISSION = 'instructor.lifecycle.activate';

    public const VACATION_PERMISSION = 'instructor.lifecycle.manage-vacation';

    public const SUSPEND_PERMISSION = 'instructor.lifecycle.suspend';

    public const ARCHIVE_PERMISSION = 'instructor.lifecycle.archive';

    public const INTERVIEW_PERMISSION = 'instructor.lifecycle.request-interview';

    /**
     * Non-KYC profile media — never admin-configurable, unlike the KYC
     * document set (see InstructorDocumentRequirementService, which
     * replaced the old REQUIRED_DOCUMENT_COLLECTIONS/
     * OPTIONAL_DOCUMENT_COLLECTIONS constants in Phase 23G).
     */
    public const PROFILE_MEDIA_COLLECTIONS = [
        'avatar',
        'introduction_video',
    ];

    public function __construct(
        private readonly AuditTrailService $auditTrail,
        private readonly InstructorDocumentRequirementService $documentRequirements,
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

    /**
     * Approved -> Active. The onboarding/review pipeline (education,
     * experience, documents, admin approval) is already fully enforced by
     * submit()/approve() before a profile can reach Approved — this method
     * only performs the final publish step, it does not re-validate
     * onboarding completeness.
     */
    public function activate(User $instructor, User $actor): UserProfile
    {
        $this->authorizeLifecycleAction($actor, self::ACTIVATE_PERMISSION);

        return $this->transitionStatus(
            $actor,
            $instructor,
            [InstructorStatus::Approved],
            InstructorStatus::Active,
            'instructor_activated',
            'Instructor activated',
        );
    }

    /**
     * Active -> Vacation. Profile, reviews, and earnings history are
     * untouched — only instructor_status changes. Phase 23M: an
     * instructor may set their own vacation status (self-service);
     * VACATION_PERMISSION continues to cover admin-initiated vacation.
     */
    public function setVacation(User $instructor, User $actor): UserProfile
    {
        $this->authorizeSelfOrLifecyclePermission($actor, $instructor, self::VACATION_PERMISSION);

        return $this->transitionStatus(
            $actor,
            $instructor,
            [InstructorStatus::Active],
            InstructorStatus::Vacation,
            'instructor_vacation_started',
            'Instructor vacation started',
        );
    }

    /**
     * Vacation -> Active. No re-approval — the existing verification/
     * approval remains valid. Phase 23M: self-service, see setVacation().
     */
    public function resumeFromVacation(User $instructor, User $actor): UserProfile
    {
        $this->authorizeSelfOrLifecyclePermission($actor, $instructor, self::VACATION_PERMISSION);

        return $this->transitionStatus(
            $actor,
            $instructor,
            [InstructorStatus::Vacation],
            InstructorStatus::Active,
            'instructor_vacation_ended',
            'Instructor vacation ended',
        );
    }

    /** Active/Vacation/Approved -> Suspended. Reason mandatory. */
    public function suspend(User $instructor, User $actor, string $reason): UserProfile
    {
        $this->authorizeLifecycleAction($actor, self::SUSPEND_PERMISSION);
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'A suspension reason is required.']);
        }

        return $this->transitionStatus(
            $actor,
            $instructor,
            [InstructorStatus::Active, InstructorStatus::Vacation, InstructorStatus::Approved],
            InstructorStatus::Suspended,
            'instructor_suspended',
            'Instructor suspended',
            $reason,
        );
    }

    /** Suspended/Vacation -> Archived. Terminal — an archived instructor never re-enters the lifecycle through this service. */
    public function archive(User $instructor, User $actor, ?string $reason = null): UserProfile
    {
        $this->authorizeLifecycleAction($actor, self::ARCHIVE_PERMISSION);

        return $this->transitionStatus(
            $actor,
            $instructor,
            [InstructorStatus::Suspended, InstructorStatus::Vacation],
            InstructorStatus::Archived,
            'instructor_archived',
            'Instructor archived',
            $reason,
        );
    }

    /** UnderReview -> InterviewRequired. Reason mandatory. */
    public function markInterviewRequired(User $instructor, User $actor, string $reason): UserProfile
    {
        $this->authorizeLifecycleAction($actor, self::INTERVIEW_PERMISSION);
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'An interview reason is required.']);
        }

        return $this->transitionStatus(
            $actor,
            $instructor,
            [InstructorStatus::UnderReview],
            InstructorStatus::InterviewRequired,
            'instructor_interview_required',
            'Instructor interview required',
            $reason,
        );
    }

    public function canActivate(User $actor): bool
    {
        return $this->hasPermission($actor, self::ACTIVATE_PERMISSION);
    }

    public function canManageVacation(User $actor): bool
    {
        return $this->hasPermission($actor, self::VACATION_PERMISSION);
    }

    public function canSuspend(User $actor): bool
    {
        return $this->hasPermission($actor, self::SUSPEND_PERMISSION);
    }

    public function canArchive(User $actor): bool
    {
        return $this->hasPermission($actor, self::ARCHIVE_PERMISSION);
    }

    public function canRequestInterview(User $actor): bool
    {
        return $this->hasPermission($actor, self::INTERVIEW_PERMISSION);
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

        foreach ($this->documentRequirements->requiredCollections() as $collection) {
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

    /**
     * Concurrency-safe guarded transition for Phase 23D lifecycle actions
     * (activate/vacation/suspend/archive/interview). Unlike
     * transitionByAdmin() (which is FROM-status-agnostic and used by the
     * pre-existing review pipeline), this re-reads the row under
     * lockForUpdate() inside the transaction and rejects the transition if
     * the current status isn't one of $allowedFrom — so two concurrent
     * admin actions on the same instructor can't both succeed: the second
     * blocks on the row lock, then sees the already-updated status and is
     * correctly rejected instead of double-writing.
     *
     * @param  list<InstructorStatus>  $allowedFrom
     */
    private function transitionStatus(
        User $admin,
        User $instructor,
        array $allowedFrom,
        InstructorStatus $to,
        string $event,
        string $description,
        ?string $reason = null,
    ): UserProfile {
        return DB::transaction(function () use ($admin, $instructor, $allowedFrom, $to, $event, $description, $reason): UserProfile {
            $profile = UserProfile::query()
                ->where('user_id', $instructor->id)
                ->lockForUpdate()
                ->first();

            if ($profile === null || ! in_array($profile->instructor_status, $allowedFrom, true)) {
                throw ValidationException::withMessages(['status' => 'Invalid instructor status transition.']);
            }

            $previousStatus = $profile->instructor_status;

            $profile->update([
                'instructor_status' => $to,
                'instructor_reviewed_at' => now(),
                'instructor_reviewed_by' => $admin->id,
                ...($reason !== null ? ['instructor_review_reason' => $reason] : []),
            ]);

            $this->auditTrail->logUser($admin, 'instructor', $event, $description, $instructor, [
                'previous_status' => $previousStatus->value,
                'new_status' => $to->value,
                ...($reason !== null ? ['reason' => $reason] : []),
            ]);

            return $profile->fresh();
        });
    }

    private function authorizeLifecycleAction(User $actor, string $permission): void
    {
        if (! $this->hasPermission($actor, $permission)) {
            throw new AuthorizationException('You are not authorized to perform this instructor lifecycle action.');
        }
    }

    /**
     * Phase 23M: an instructor acting on their own vacation status needs
     * no permission grant — self-service. Anyone else (including staff)
     * still needs the dedicated permission, exactly as before.
     */
    private function authorizeSelfOrLifecyclePermission(User $actor, User $instructor, string $permission): void
    {
        if ($actor->id === $instructor->id) {
            return;
        }

        $this->authorizeLifecycleAction($actor, $permission);
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
        if (in_array($collection, [...$this->documentRequirements->activeCollectionNames(), ...self::PROFILE_MEDIA_COLLECTIONS], true)) {
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
