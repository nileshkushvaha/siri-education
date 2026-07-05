<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Enums\InstructorStatus;
use App\Models\AcademicCategory;
use App\Models\AcademicLevel;
use App\Models\Language;
use App\Models\Subject;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserEducation;
use App\Models\UserExperience;
use App\Services\Instructor\InstructorOnboardingService;
use App\Services\Instructor\InstructorService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InstructorOnboardingServiceTest extends TestCase
{
    use RefreshDatabase;

    private InstructorOnboardingService $onboarding;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'Update:User', 'guard_name' => 'web']);

        $this->onboarding = app(InstructorOnboardingService::class);
    }

    public function test_user_can_start_instructor_onboarding_once(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($user);
        $firstProfile = $this->onboarding->start($user);
        $secondProfile = $this->onboarding->start($user->fresh());

        $this->assertSame($firstProfile->id, $secondProfile->id);
        $this->assertSame(InstructorStatus::Draft, $secondProfile->instructor_status);
        $this->assertTrue($user->fresh()->hasRole('instructor'));
        $this->assertDatabaseCount('user_profiles', 1);
    }

    public function test_duplicate_profile_or_application_is_not_created(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $profileId = $user->profile->id;

        $this->actingAs($user);
        $this->onboarding->start($user);
        $this->onboarding->start($user->fresh());

        $this->assertDatabaseHas('user_profiles', ['id' => $profileId, 'user_id' => $user->id]);
        $this->assertSame(1, $user->profile()->withTrashed()->count());
    }

    public function test_user_cannot_submit_incomplete_application(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($user);

        $this->expectException(ValidationException::class);

        $this->onboarding->submit($user);
    }

    public function test_user_can_submit_complete_application(): void
    {
        $user = $this->completeApplicant();

        $this->actingAs($user);
        $profile = $this->onboarding->submit($user->fresh());

        $this->assertSame(InstructorStatus::Submitted, $profile->instructor_status);
        $this->assertNotNull($profile->instructor_application_submitted_at);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'instructor',
            'event' => 'application_submitted',
            'causer_id' => $user->id,
        ]);
    }

    public function test_submitted_instructor_is_not_bookable(): void
    {
        $this->assertNotContains(InstructorStatus::Submitted, InstructorStatus::bookable());
    }

    public function test_approved_and_active_instructors_are_bookable(): void
    {
        $this->assertSame([InstructorStatus::Approved, InstructorStatus::Active], InstructorStatus::bookable());
    }

    public function test_rejected_suspended_and_archived_instructors_are_not_bookable(): void
    {
        $this->assertNotContains(InstructorStatus::Rejected, InstructorStatus::bookable());
        $this->assertNotContains(InstructorStatus::Suspended, InstructorStatus::bookable());
        $this->assertNotContains(InstructorStatus::Archived, InstructorStatus::bookable());
    }

    public function test_admin_approval_requires_permission(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $instructor = $this->completeApplicant();

        $this->expectException(AuthorizationException::class);

        $this->onboarding->approve($admin, $instructor, 'Looks good');
    }

    public function test_admin_rejection_requires_reason(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->givePermissionTo('Update:User');
        $instructor = $this->completeApplicant();

        $this->expectException(ValidationException::class);

        $this->onboarding->reject($admin, $instructor, '');
    }

    public function test_kyc_documents_are_private_media_library_attachments(): void
    {
        $user = $this->completeApplicant();
        $profile = $user->profile->fresh();

        foreach (InstructorOnboardingService::REQUIRED_DOCUMENT_COLLECTIONS as $collection) {
            $media = $profile->getFirstMedia($collection);

            $this->assertNotNull($media);
            $this->assertSame('local', $media->disk);
            $this->assertSame($collection, $media->collection_name);
        }
    }

    public function test_subject_selection_uses_subject_master_data(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $subject = $this->subject('Physics');
        $level = $this->academicLevel();
        $language = Language::factory()->create(['name' => 'English']);

        $this->actingAs($user);
        $this->onboarding->updateProfile($user, [
            'headline' => 'Physics mentor',
            'bio' => 'I help students understand physics.',
            'teaching_experience_summary' => 'Ten years in STEM classrooms.',
            'teaching_philosophy' => 'Teach from first principles.',
            'subject_ids' => [$subject->id],
            'academic_level_ids' => [$level->id],
            'teaching_language_ids' => [$language->id],
        ]);

        $this->assertDatabaseHas('teacher_subjects', [
            'teacher_id' => $user->id,
            'subject_id' => $subject->id,
            'subject' => 'Physics',
        ]);
    }

    public function test_legacy_teacher_subject_fallback_still_displays(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('instructor');
        TeacherSubject::factory()->create([
            'teacher_id' => $user->id,
            'subject_id' => null,
            'subject' => 'legacy_maths',
        ]);

        $subjects = app(InstructorService::class)->subjectsFor($user);

        $this->assertSame('Legacy Maths', $subjects->first()['name']);
        $this->assertSame('legacy_maths', $subjects->first()['slug']);
    }

    public function test_activity_logs_are_written_for_review_transitions(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->givePermissionTo('Update:User');
        $instructor = $this->completeApplicant();

        $this->actingAs($admin);
        $this->onboarding->markUnderReview($admin, $instructor);
        $this->onboarding->requestDocuments($admin, $instructor, 'Upload a clearer government ID');
        $this->onboarding->approve($admin, $instructor, 'Verified credentials');

        $this->assertDatabaseHas('activity_log', ['log_name' => 'instructor', 'event' => 'application_under_review']);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'instructor', 'event' => 'documents_requested']);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'instructor', 'event' => 'application_approved']);
        $this->assertTrue((bool) $instructor->profile->fresh()->is_instructor_verified);
    }

    private function completeApplicant(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $subject = $this->subject('Algebra');
        $level = $this->academicLevel();
        $language = Language::factory()->create(['name' => 'English']);

        $this->actingAs($user);
        $this->onboarding->updateProfile($user, [
            'headline' => 'STEM instructor',
            'bio' => 'Experienced STEM instructor for middle and high school learners.',
            'teaching_experience_summary' => 'Eight years teaching math and science.',
            'teaching_philosophy' => 'Students learn best through guided practice.',
            'subject_ids' => [$subject->id],
            'academic_level_ids' => [$level->id],
            'teaching_language_ids' => [$language->id],
        ]);

        UserEducation::factory()->for($user)->create(['status' => 'active']);
        UserExperience::factory()->for($user)->create(['status' => 'active']);

        foreach (InstructorOnboardingService::REQUIRED_DOCUMENT_COLLECTIONS as $collection) {
            $user->profile->addMedia(UploadedFile::fake()->image($collection.'.jpg'))
                ->toMediaCollection($collection);
        }

        return $user->fresh(['profile.media', 'teacherSubjects']);
    }

    private function subject(string $name): Subject
    {
        $category = AcademicCategory::query()->create([
            'name' => 'STEM',
            'slug' => 'stem-'.strtolower($name),
        ]);

        return Subject::query()->create([
            'academic_category_id' => $category->id,
            'name' => $name,
            'slug' => strtolower($name),
            'status' => 'active',
        ]);
    }

    private function academicLevel(): AcademicLevel
    {
        return AcademicLevel::query()->create([
            'name' => 'High School',
            'slug' => 'high-school-'.uniqid(),
            'min_grade' => 9,
            'max_grade' => 12,
            'status' => 'active',
        ]);
    }
}
