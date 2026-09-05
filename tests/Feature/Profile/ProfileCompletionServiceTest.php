<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Enums\EducationLevel;
use App\Enums\EmploymentType;
use App\Enums\LearningGoalStatus;
use App\Enums\LearningGoalType;
use App\Models\AcademicCategory;
use App\Models\AcademicLevel;
use App\Models\Country;
use App\Models\Language;
use App\Models\StudentLearningGoal;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserEducation;
use App\Models\UserExperience;
use App\Services\Profile\ProfileCompletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProfileCompletionServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProfileCompletionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProfileCompletionService::class);
        Storage::fake('public');
    }

    public function test_a_brand_new_user_has_low_completion(): void
    {
        $user = User::factory()->create([
            'first_name' => null,
            'last_name' => null,
            'email_verified_at' => null,
        ]);

        $this->assertSame(0, $this->service->calculate($user));
    }

    public function test_completion_increases_as_sections_are_filled(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Jane',
            'last_name' => null,
            'email_verified_at' => null,
        ]);

        $partial = $this->service->calculate($user);

        $user->update(['last_name' => 'Doe', 'email_verified_at' => now()]);
        $user->profile->update(['headline' => 'Engineer', 'bio' => 'Hello', 'phone' => '12345']);

        $more = $this->service->calculate($user->fresh());

        $this->assertGreaterThan($partial, $more);
    }

    public function test_a_fully_completed_profile_is_100_percent(): void
    {
        $country = Country::factory()->create();
        $user = User::factory()->create([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email_verified_at' => now(),
        ]);
        $user->profile->update([
            'headline' => 'Engineer',
            'bio' => 'Hello world',
            'phone' => '12345',
            'address' => '123 Main St',
            'country_id' => $country->id,
            'website' => 'https://example.com',
            'facebook' => 'https://facebook.com/jane',
            'twitter' => 'https://twitter.com/jane',
            'linkedin' => 'https://linkedin.com/in/jane',
            'github' => 'https://github.com/jane',
            'instagram' => 'https://instagram.com/jane',
            'youtube' => 'https://youtube.com/@jane',
        ]);
        $user->profile->addMedia(UploadedFile::fake()->image('avatar.jpg'))->toMediaCollection('avatar');

        UserExperience::factory()->for($user)->create([
            'employment_type' => EmploymentType::FullTime,
            'is_current' => true,
            'start_date' => now()->subYear(),
            'end_date' => null,
        ]);
        UserEducation::factory()->for($user)->create([
            'education_level' => EducationLevel::Bachelor,
            'is_current' => false,
            'start_date' => now()->subYears(4),
            'end_date' => now()->subYears(1),
        ]);

        $this->assertSame(100, $this->service->calculate($user->fresh()));
    }

    public function test_experience_and_education_sections_contribute_their_full_weight(): void
    {
        $withoutRecords = User::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe', 'email_verified_at' => now()]);
        $baseline = $this->service->calculate($withoutRecords);

        UserExperience::factory()->for($withoutRecords)->create([
            'employment_type' => EmploymentType::FullTime,
            'is_current' => true,
            'start_date' => now()->subYear(),
            'end_date' => null,
        ]);

        $withExperience = $this->service->calculate($withoutRecords->fresh());

        $this->assertSame(30, $withExperience - $baseline);
    }

    public function test_recalculate_and_store_persists_the_percentage(): void
    {
        $user = User::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe', 'email_verified_at' => now()]);

        $percentage = $this->service->recalculateAndStore($user);

        $this->assertSame($percentage, $user->profile->fresh()->profile_completion);
    }

    public function test_breakdown_reports_each_weighted_section_individually(): void
    {
        $user = User::factory()->create(['first_name' => 'Jane', 'last_name' => null]);

        $breakdown = $this->service->breakdown($user);

        $this->assertArrayHasKey('basic_profile', $breakdown);
        $this->assertArrayHasKey('avatar', $breakdown);
        $this->assertArrayHasKey('bio', $breakdown);
        $this->assertArrayHasKey('experience', $breakdown);
        $this->assertArrayHasKey('education', $breakdown);
        $this->assertArrayHasKey('social_links', $breakdown);

        $this->assertSame(20, $breakdown['basic_profile']['weight']);
        $this->assertSame(10, $breakdown['avatar']['weight']);
        $this->assertSame(10, $breakdown['bio']['weight']);
        $this->assertSame(30, $breakdown['experience']['weight']);
        $this->assertSame(20, $breakdown['education']['weight']);
        $this->assertSame(10, $breakdown['social_links']['weight']);

        $this->assertSame(0.0, $breakdown['experience']['score']);
        $this->assertSame(0.0, $breakdown['avatar']['score']);
        $this->assertGreaterThan(0.0, $breakdown['basic_profile']['score']);
        $this->assertLessThan(1.0, $breakdown['basic_profile']['score']);
    }

    // ── Students get a student checklist ─────────────────────────────

    private function student(): User
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $user = User::factory()->create(['first_name' => 'Rohit', 'last_name' => null, 'email_verified_at' => now()]);
        $user->assignRole('student');

        return $user->fresh();
    }

    /** Regression: a student was shown "still missing: Work experience, Education, Social links" — sections a student can never fill. */
    public function test_a_student_is_not_scored_on_instructor_sections(): void
    {
        $breakdown = $this->service->breakdown($this->student());

        $this->assertArrayNotHasKey('experience', $breakdown);
        $this->assertArrayNotHasKey('education', $breakdown);
        $this->assertArrayNotHasKey('social_links', $breakdown);
        $this->assertArrayNotHasKey('bio', $breakdown);
        $this->assertSame(['basic_profile', 'avatar', 'phone', 'academic_profile', 'learning_goals'], array_keys($breakdown));
        $this->assertSame(100, (int) array_sum(array_column($breakdown, 'weight')));
    }

    public function test_a_student_reaches_100_percent_with_student_facing_information_only(): void
    {
        $student = $this->student();
        $student->update(['last_name' => 'Sharma']);
        $student->profile->update([
            'phone' => '+12025551023',
            'phone_e164' => '+12025551023',
            'country_id' => Country::factory()->create()->id,
            'timezone' => 'America/New_York',
            'date_of_birth' => '2010-05-01',
            'student_academic_level_id' => AcademicLevel::create(['name' => 'Grade 10', 'slug' => 'grade-10', 'min_grade' => 10, 'max_grade' => 10])->id,
            'student_preferred_language_id' => Language::factory()->create()->id,
        ]);
        $category = AcademicCategory::create(['name' => 'Science', 'slug' => 'science']);
        $biology = Subject::create(['academic_category_id' => $category->id, 'name' => 'Biology', 'slug' => 'biology']);
        $student->preferredSubjects()->attach($biology->id);
        StudentLearningGoal::create([
            'user_id' => $student->id,
            'subject_id' => $biology->id,
            'title' => 'Pass Grade 10 Biology',
            'type' => LearningGoalType::Academic,
            'status' => LearningGoalStatus::Active,
            'priority' => 1,
        ]);
        $student->profile->addMedia(UploadedFile::fake()->image('avatar.jpg'))->toMediaCollection('avatar');

        $this->assertSame(100, $this->service->calculate($student->fresh()));
    }

    public function test_an_instructor_who_is_also_a_student_keeps_the_professional_checklist(): void
    {
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        $user = $this->student();
        $user->assignRole('instructor');

        $this->assertArrayHasKey('experience', $this->service->breakdown($user->fresh()));
    }
}
