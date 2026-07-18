<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Enums\EducationLevel;
use App\Enums\EmploymentType;
use App\Enums\InstructorStatus;
use App\Livewire\Frontend\Instructor\OnboardingWizard;
use App\Models\AcademicCategory;
use App\Models\AcademicLevel;
use App\Models\InstructorDocumentRequirement;
use App\Models\Language;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserEducation;
use App\Services\Instructor\InstructorDocumentRequirementService;
use App\Services\Instructor\InstructorOnboardingService;
use Database\Seeders\InstructorDocumentRequirementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InstructorOnboardingWizardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'Update:User', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => InstructorOnboardingService::REVIEW_PERMISSION, 'guard_name' => 'web']);
        $this->seed(InstructorDocumentRequirementSeeder::class);
    }

    public function test_guest_cannot_access_onboarding_wizard(): void
    {
        $this->get(route('dashboard.instructor.onboarding'))
            ->assertRedirect(route('auth.login'));
    }

    public function test_verified_user_can_access_onboarding_wizard(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('student');

        $this->actingAs($user)
            ->get(route('dashboard.instructor.onboarding'))
            ->assertOk()
            ->assertSee('Instructor Onboarding');
    }

    public function test_user_can_start_onboarding_once_from_wizard(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('student');
        // Phase 23C: start() is gated by InstructorEligibilityService for a
        // first-time attempt, which requires some education signal on file —
        // see InstructorApplicationEntryTest/InstructorEligibilityServiceTest
        // for eligibility-specific coverage; this test is about the
        // start-once/resume behavior, not eligibility itself.
        UserEducation::factory()->create(['user_id' => $user->id, 'education_level' => EducationLevel::Bachelor]);

        Livewire::actingAs($user)
            ->test(OnboardingWizard::class)
            ->call('start')
            ->call('start');

        $this->assertSame(1, $user->profile()->withTrashed()->count());
        $this->assertSame(InstructorStatus::Draft, $user->fresh()->profile->instructor_status);
    }

    public function test_non_email_verified_user_cannot_submit_from_wizard(): void
    {
        $user = User::factory()->unverified()->create(['status' => 'active']);
        $user->assignRole('student');

        Livewire::actingAs($user)
            ->test(OnboardingWizard::class)
            ->call('submit')
            ->assertHasErrors(['email']);
    }

    public function test_user_can_update_professional_profile_fields(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        Livewire::actingAs($user)
            ->test(OnboardingWizard::class)
            ->set('profile.headline', 'STEM mentor')
            ->set('profile.bio', 'I teach STEM with care.')
            ->set('profile.teaching_experience_summary', 'Ten years teaching robotics.')
            ->set('profile.teaching_philosophy', 'Students learn by doing.')
            ->call('saveProfile')
            ->assertHasNoErrors();

        $profile = $user->fresh()->profile;
        $this->assertSame('STEM mentor', $profile->headline);
        $this->assertSame('Students learn by doing.', $profile->instructor_teaching_philosophy);
    }

    public function test_user_can_select_master_subjects_levels_and_languages_without_free_text_input(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        [$subject, $level, $language] = $this->masterData();

        Livewire::actingAs($user)
            ->test(OnboardingWizard::class)
            ->set('subjectIds', [$subject->id])
            ->set('academicLevelIds', [$level->id])
            ->set('teachingLanguageIds', [(string) $language->id])
            ->call('savePreferences')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('teacher_subjects', [
            'teacher_id' => $user->id,
            'subject_id' => $subject->id,
            'subject' => $subject->name,
        ]);
    }

    public function test_user_can_add_and_update_education(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $component = Livewire::actingAs($user)
            ->test(OnboardingWizard::class)
            ->set('educationForm.institution_name', 'State University')
            ->set('educationForm.degree', 'Bachelor of Science')
            ->set('educationForm.field_of_study', 'Physics')
            ->set('educationForm.education_level', EducationLevel::Bachelor->value)
            ->set('educationForm.start_date', '2015-01-01')
            ->set('educationForm.end_date', '2019-01-01')
            ->call('saveEducation')
            ->assertHasNoErrors();

        $education = $user->educations()->firstOrFail();

        $component
            ->call('editEducation', $education->id)
            ->set('educationForm.degree', 'Bachelor of Applied Science')
            ->call('saveEducation')
            ->assertHasNoErrors();

        $this->assertSame('Bachelor of Applied Science', $education->fresh()->degree);
    }

    public function test_user_can_add_and_update_experience(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $component = Livewire::actingAs($user)
            ->test(OnboardingWizard::class)
            ->set('experienceForm.organization_name', 'Learning Lab')
            ->set('experienceForm.designation', 'Teacher')
            ->set('experienceForm.employment_type', EmploymentType::FullTime->value)
            ->set('experienceForm.start_date', '2020-01-01')
            ->set('experienceForm.end_date', '2022-01-01')
            ->call('saveExperience')
            ->assertHasNoErrors();

        $experience = $user->experiences()->firstOrFail();

        $component
            ->call('editExperience', $experience->id)
            ->set('experienceForm.designation', 'Lead Teacher')
            ->call('saveExperience')
            ->assertHasNoErrors();

        $this->assertSame('Lead Teacher', $experience->fresh()->designation);
    }

    public function test_user_can_upload_required_private_kyc_documents(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        Livewire::actingAs($user)
            ->test(OnboardingWizard::class)
            ->set('governmentId', UploadedFile::fake()->image('government.jpg'))
            ->call('uploadDocument', 'government_id')
            ->set('addressProof', UploadedFile::fake()->image('address.jpg'))
            ->call('uploadDocument', 'address_proof')
            ->set('educationCertificate', UploadedFile::fake()->image('education.jpg'))
            ->call('uploadDocument', 'education_certificate')
            ->set('teachingCertificate', UploadedFile::fake()->image('teaching.jpg'))
            ->call('uploadDocument', 'teaching_certificate')
            ->set('resume', UploadedFile::fake()->image('resume.jpg'))
            ->call('uploadDocument', 'resume')
            ->assertHasNoErrors();

        foreach (app(InstructorDocumentRequirementService::class)->requiredCollections() as $collection) {
            $media = $user->fresh()->profile->getFirstMedia($collection);
            $this->assertNotNull($media);
            $this->assertSame('local', $media->disk);
        }
    }

    public function test_upload_validation_error_uses_the_requirement_label_not_the_property_name(): void
    {
        // An admin can relabel a requirement (e.g. government_id -> "Pan
        // Card") without renaming its underlying collection_name/property
        // — the error message must follow the label, not fall back to a
        // humanized "government id".
        InstructorDocumentRequirement::query()->where('collection_name', 'government_id')->update(['label' => 'Pan Card']);

        $user = User::factory()->create(['status' => 'active']);

        $component = Livewire::actingAs($user)
            ->test(OnboardingWizard::class)
            ->set('governmentId', UploadedFile::fake()->create('resume.exe', 10))
            ->call('uploadDocument', 'government_id')
            ->assertHasErrors(['governmentId' => 'mimes']);

        $message = $component->errors()->first('governmentId');
        $this->assertStringContainsString('Pan Card', $message);
        $this->assertStringNotContainsString('government id', $message);
    }

    public function test_complete_application_can_submit_and_cannot_submit_twice(): void
    {
        $user = $this->completeApplicantThroughWizard();

        Livewire::actingAs($user)
            ->test(OnboardingWizard::class)
            ->call('submit')
            ->assertHasNoErrors()
            ->call('submit')
            ->assertHasErrors(['application']);

        $this->assertSame(InstructorStatus::Submitted, $user->fresh()->profile->instructor_status);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'instructor',
            'event' => 'application_submitted',
            'causer_id' => $user->id,
        ]);
    }

    public function test_submitted_applicant_is_not_public_or_bookable(): void
    {
        $user = $this->completeApplicantThroughWizard();
        app(InstructorOnboardingService::class)->submit($user);

        auth()->logout();

        $this->get(route('instructors.show', $user))->assertForbidden();
        $this->assertNotContains(InstructorStatus::Submitted, InstructorStatus::bookable());
    }

    public function test_admin_status_select_cannot_bypass_reason_required_review_flow(): void
    {
        $this->assertStringNotContainsString(
            "Select::make('instructor_status')",
            file_get_contents(app_path('Filament/Resources/Users/Schemas/UserForm.php')),
        );
    }

    private function completeApplicantThroughWizard(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        [$subject, $level, $language] = $this->masterData();

        Livewire::actingAs($user)
            ->test(OnboardingWizard::class)
            ->set('profile.headline', 'STEM instructor')
            ->set('profile.bio', 'Experienced STEM instructor.')
            ->set('profile.teaching_experience_summary', 'Eight years teaching.')
            ->set('profile.teaching_philosophy', 'Practice first.')
            ->call('saveProfile')
            ->set('subjectIds', [$subject->id])
            ->set('academicLevelIds', [$level->id])
            ->set('teachingLanguageIds', [(string) $language->id])
            ->call('savePreferences')
            ->set('educationForm.institution_name', 'State University')
            ->set('educationForm.degree', 'Bachelor of Science')
            ->set('educationForm.education_level', EducationLevel::Bachelor->value)
            ->set('educationForm.start_date', '2015-01-01')
            ->set('educationForm.end_date', '2019-01-01')
            ->call('saveEducation')
            ->set('experienceForm.organization_name', 'Learning Lab')
            ->set('experienceForm.designation', 'Teacher')
            ->set('experienceForm.employment_type', EmploymentType::FullTime->value)
            ->set('experienceForm.start_date', '2020-01-01')
            ->set('experienceForm.end_date', '2022-01-01')
            ->call('saveExperience')
            ->set('governmentId', UploadedFile::fake()->image('government.jpg'))
            ->call('uploadDocument', 'government_id')
            ->set('addressProof', UploadedFile::fake()->image('address.jpg'))
            ->call('uploadDocument', 'address_proof')
            ->set('educationCertificate', UploadedFile::fake()->image('education.jpg'))
            ->call('uploadDocument', 'education_certificate')
            ->set('teachingCertificate', UploadedFile::fake()->image('teaching.jpg'))
            ->call('uploadDocument', 'teaching_certificate')
            ->set('resume', UploadedFile::fake()->image('resume.jpg'))
            ->call('uploadDocument', 'resume');

        return $user->fresh(['profile.media', 'teacherSubjects', 'educations', 'experiences']);
    }

    private function masterData(): array
    {
        $category = AcademicCategory::query()->create([
            'name' => 'STEM',
            'slug' => 'stem-'.uniqid(),
        ]);

        $subject = Subject::query()->create([
            'academic_category_id' => $category->id,
            'name' => 'Physics',
            'slug' => 'physics-'.uniqid(),
            'status' => 'active',
        ]);

        $level = AcademicLevel::query()->create([
            'name' => 'High School',
            'slug' => 'high-school-'.uniqid(),
            'min_grade' => 9,
            'max_grade' => 12,
            'status' => 'active',
        ]);

        $language = Language::factory()->create(['name' => 'English', 'status' => 'active']);

        return [$subject, $level, $language];
    }
}
