<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Contracts\InstructorEligibilityServiceInterface;
use App\Enums\EducationLevel;
use App\Enums\InstructorEligibilityCode;
use App\Enums\InstructorStatus;
use App\Models\AcademicLevel;
use App\Models\User;
use App\Models\UserEducation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class InstructorEligibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private InstructorEligibilityServiceInterface $eligibility;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        $this->eligibility = app(InstructorEligibilityServiceInterface::class);
    }

    public function test_graduate_with_bachelor_education_is_eligible(): void
    {
        $user = $this->verifiedActiveUser();
        UserEducation::factory()->create(['user_id' => $user->id, 'education_level' => EducationLevel::Bachelor]);

        $result = $this->eligibility->evaluate($user->fresh());

        $this->assertTrue($result->eligible);
        $this->assertSame(InstructorEligibilityCode::Eligible, $result->code);
        $this->assertNull($result->reason);
    }

    public function test_professional_certification_holder_is_eligible(): void
    {
        $user = $this->verifiedActiveUser();
        UserEducation::factory()->create(['user_id' => $user->id, 'education_level' => EducationLevel::ProfessionalCertification]);

        $result = $this->eligibility->evaluate($user->fresh());

        $this->assertTrue($result->eligible);
    }

    public function test_student_academic_level_class_10_is_rejected(): void
    {
        $user = $this->verifiedActiveUser();
        $user->assignRole('student');
        $highSchool = $this->academicLevel('High School', 'high-school', 9, 12);
        $user->profile()->update(['student_academic_level_id' => $highSchool->id]);

        $result = $this->eligibility->evaluate($user->fresh());

        $this->assertFalse($result->eligible);
        $this->assertSame(InstructorEligibilityCode::SchoolStudentRestricted, $result->code);
        $this->assertNotNull($result->reason);
    }

    public function test_student_academic_level_class_11_is_rejected(): void
    {
        $user = $this->verifiedActiveUser();
        $user->assignRole('student');
        $highSchool = $this->academicLevel('High School', 'high-school', 9, 12);
        $user->profile()->update(['student_academic_level_id' => $highSchool->id]);

        $result = $this->eligibility->evaluate($user->fresh());

        $this->assertFalse($result->eligible);
        $this->assertSame(InstructorEligibilityCode::SchoolStudentRestricted, $result->code);
    }

    public function test_student_academic_level_class_12_is_rejected(): void
    {
        $user = $this->verifiedActiveUser();
        $user->assignRole('student');
        $highSchool = $this->academicLevel('High School', 'high-school', 9, 12);
        $user->profile()->update(['student_academic_level_id' => $highSchool->id]);

        $result = $this->eligibility->evaluate($user->fresh());

        $this->assertFalse($result->eligible);
        $this->assertSame(InstructorEligibilityCode::SchoolStudentRestricted, $result->code);
    }

    public function test_middle_school_and_primary_students_are_also_restricted(): void
    {
        $user = $this->verifiedActiveUser();
        $user->assignRole('student');
        $middleSchool = $this->academicLevel('Middle School', 'middle-school', 6, 8);
        $user->profile()->update(['student_academic_level_id' => $middleSchool->id]);

        $result = $this->eligibility->evaluate($user->fresh());

        $this->assertFalse($result->eligible);
        $this->assertSame(InstructorEligibilityCode::SchoolStudentRestricted, $result->code);
    }

    public function test_undergraduate_student_academic_level_is_eligible(): void
    {
        $user = $this->verifiedActiveUser();
        $user->assignRole('student');
        $undergraduate = $this->academicLevel('Undergraduate', 'undergraduate', null, null);
        $user->profile()->update(['student_academic_level_id' => $undergraduate->id]);

        $result = $this->eligibility->evaluate($user->fresh());

        $this->assertTrue($result->eligible);
    }

    public function test_unverified_email_is_rejected(): void
    {
        $user = User::factory()->unverified()->create(['status' => User::STATUS_ACTIVE]);
        UserEducation::factory()->create(['user_id' => $user->id, 'education_level' => EducationLevel::Bachelor]);

        $result = $this->eligibility->evaluate($user->fresh());

        $this->assertFalse($result->eligible);
        $this->assertSame(InstructorEligibilityCode::EmailNotVerified, $result->code);
    }

    public function test_suspended_account_is_rejected(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_SUSPENDED, 'email_verified_at' => now()]);
        UserEducation::factory()->create(['user_id' => $user->id, 'education_level' => EducationLevel::Bachelor]);

        $result = $this->eligibility->evaluate($user->fresh());

        $this->assertFalse($result->eligible);
        $this->assertSame(InstructorEligibilityCode::AccountSuspended, $result->code);
    }

    public function test_user_who_already_has_an_instructor_application_is_rejected(): void
    {
        $user = $this->verifiedActiveUser();
        $user->assignRole('instructor');
        $user->profile()->update(['instructor_status' => InstructorStatus::Draft]);

        $result = $this->eligibility->evaluate($user->fresh());

        $this->assertFalse($result->eligible);
        $this->assertSame(InstructorEligibilityCode::AlreadyInstructor, $result->code);
    }

    public function test_user_with_no_education_data_at_all_is_missing_education_information(): void
    {
        $user = $this->verifiedActiveUser();

        $result = $this->eligibility->evaluate($user->fresh());

        $this->assertFalse($result->eligible);
        $this->assertSame(InstructorEligibilityCode::MissingEducationInformation, $result->code);
    }

    private function verifiedActiveUser(): User
    {
        return User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    private function academicLevel(string $name, string $slug, ?int $minGrade, ?int $maxGrade): AcademicLevel
    {
        return AcademicLevel::query()->create([
            'name' => $name,
            'slug' => $slug,
            'min_grade' => $minGrade,
            'max_grade' => $maxGrade,
            'status' => 'active',
            'display_order' => 0,
        ]);
    }
}
