<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Curriculum\Services\AcademicContextResolver;
use App\Curriculum\Services\CurriculumService;
use App\Curriculum\Services\EducationSystemService;
use App\Curriculum\Services\InstructorAcademicEligibilityResolver;
use App\Curriculum\Services\InstructorAcademicEligibilityService;
use App\Models\AcademicCategory;
use App\Models\AcademicLevel;
use App\Models\Country;
use App\Models\Curriculum;
use App\Models\EducationSystem;
use App\Models\InstructorCurriculumEligibility;
use App\Models\Subject;
use App\Models\SubjectTopic;
use App\Models\TeacherSubject;
use App\Models\User;
use Database\Seeders\AcademicPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Runtime resolver: AcademicContextResolver -> AcademicContextData ->
 * InstructorAcademicEligibilityResolver -> eligible/not eligible.
 * Mirrors AcademicContextResolverTest's fixture-building conventions.
 */
class InstructorAcademicEligibilityResolverTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AcademicPermissionSeeder::class);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        $managerRole = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole($managerRole);
    }

    private function contextResolver(): AcademicContextResolver
    {
        return app(AcademicContextResolver::class);
    }

    private function eligibilityResolver(): InstructorAcademicEligibilityResolver
    {
        return app(InstructorAcademicEligibilityResolver::class);
    }

    private function eligibilityService(): InstructorAcademicEligibilityService
    {
        return app(InstructorAcademicEligibilityService::class);
    }

    private function educationSystemService(): EducationSystemService
    {
        return app(EducationSystemService::class);
    }

    private function curriculumService(): CurriculumService
    {
        return app(CurriculumService::class);
    }

    private function subject(string $slug): Subject
    {
        $category = AcademicCategory::query()->firstOrCreate(['slug' => 'resolver-general'], ['name' => 'Resolver General']);

        return Subject::create([
            'academic_category_id' => $category->id,
            'name' => ucfirst($slug),
            'slug' => $slug,
        ]);
    }

    private function level(string $slug): AcademicLevel
    {
        return AcademicLevel::create([
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'min_grade' => 1,
            'max_grade' => 12,
        ]);
    }

    private function publishedCurriculum(Subject $subject, AcademicLevel $level, string $name): Curriculum
    {
        $curriculum = $this->curriculumService()->createCurriculum($this->admin, [
            'subject_id' => $subject->id,
            'academic_level_id' => $level->id,
            'name' => $name,
        ]);

        $version = $curriculum->latestVersion();
        $module = $this->curriculumService()->addModule($this->admin, $version, ['title' => 'Module 1']);
        $topic = SubjectTopic::factory()->create(['subject_id' => $subject->id]);
        $this->curriculumService()->assignTopic($this->admin, $module, $topic);
        $this->curriculumService()->publish($this->admin, $version);

        return $curriculum->refresh();
    }

    /** @return array{country: Country, system: EducationSystem, level: AcademicLevel, subject: Subject, curriculum: Curriculum} */
    private function buildFixture(string $prefix): array
    {
        $country = Country::factory()->create();
        $system = $this->educationSystemService()->createEducationSystem($this->admin, ['name' => "{$prefix} System", 'slug' => strtolower($prefix).'-r-system']);
        $level = $this->level(strtolower($prefix).'-r-level');
        $subject = $this->subject(strtolower($prefix).'-r-subject');
        $curriculum = $this->publishedCurriculum($subject, $level, "{$prefix} R Curriculum");

        $this->educationSystemService()->mapToCountry($this->admin, $system, $country);
        $this->educationSystemService()->mapToAcademicLevel($this->admin, $system, $level);
        $this->educationSystemService()->mapToCurriculum($this->admin, $system, $curriculum);

        return compact('country', 'system', 'level', 'subject', 'curriculum');
    }

    private function instructorTeaching(Subject $subject): User
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');

        TeacherSubject::factory()->create([
            'teacher_id' => $instructor->id,
            'subject' => $subject->name,
            'subject_id' => $subject->id,
            'grade_from' => 1,
            'grade_to' => 12,
        ]);

        return $instructor;
    }

    public function test_full_valid_context_resolves_eligible_when_eligibility_row_exists(): void
    {
        $fixture = $this->buildFixture('Full');
        $instructor = $this->instructorTeaching($fixture['subject']);
        $this->eligibilityService()->assign($this->admin, $instructor, $fixture['system'], $fixture['curriculum']);

        $context = $this->contextResolver()->resolveContext($fixture['country'], $fixture['system'], $fixture['level'], $fixture['subject'], $fixture['curriculum']);

        $this->assertTrue($this->eligibilityResolver()->isEligible($instructor, $context));
        $this->eligibilityResolver()->assertEligible($instructor, $context);
        $this->addToAssertionCount(1);
    }

    public function test_missing_eligibility_row_resolves_ineligible(): void
    {
        $fixture = $this->buildFixture('Missing');
        $instructor = $this->instructorTeaching($fixture['subject']);
        // Deliberately never call assign().

        $context = $this->contextResolver()->resolveContext($fixture['country'], $fixture['system'], $fixture['level'], $fixture['subject'], $fixture['curriculum']);

        $this->assertFalse($this->eligibilityResolver()->isEligible($instructor, $context));
    }

    public function test_inactive_eligibility_resolves_ineligible(): void
    {
        $fixture = $this->buildFixture('Inactive');
        $instructor = $this->instructorTeaching($fixture['subject']);
        $eligibility = $this->eligibilityService()->assign($this->admin, $instructor, $fixture['system'], $fixture['curriculum']);
        $this->eligibilityService()->deactivate($this->admin, $eligibility);

        $context = $this->contextResolver()->resolveContext($fixture['country'], $fixture['system'], $fixture['level'], $fixture['subject'], $fixture['curriculum']);

        $this->assertFalse($this->eligibilityResolver()->isEligible($instructor, $context));
    }

    public function test_wrong_curriculum_resolves_ineligible(): void
    {
        $fixtureA = $this->buildFixture('WrongCurA');
        $fixtureB = $this->buildFixture('WrongCurB');
        $instructor = $this->instructorTeaching($fixtureA['subject']);
        $this->eligibilityService()->assign($this->admin, $instructor, $fixtureA['system'], $fixtureA['curriculum']);

        // Eligible under fixture A's context...
        $contextA = $this->contextResolver()->resolveContext($fixtureA['country'], $fixtureA['system'], $fixtureA['level'], $fixtureA['subject'], $fixtureA['curriculum']);
        $this->assertTrue($this->eligibilityResolver()->isEligible($instructor, $contextA));

        // ...but a different Curriculum (even under a system the instructor has no row for) is not eligible.
        $instructorB = $this->instructorTeaching($fixtureB['subject']);
        $contextB = $this->contextResolver()->resolveContext($fixtureB['country'], $fixtureB['system'], $fixtureB['level'], $fixtureB['subject'], $fixtureB['curriculum']);
        $this->assertFalse($this->eligibilityResolver()->isEligible($instructor, $contextB));
        $this->assertFalse($this->eligibilityResolver()->isEligible($instructorB, $contextA));
    }

    public function test_wrong_education_system_resolves_ineligible(): void
    {
        // Two systems mapped to the SAME globally-applicable curriculum,
        // eligibility granted only under system A.
        $subject = $this->subject('shared-sys-subject');
        $level = $this->level('shared-sys-level');
        $curriculum = $this->publishedCurriculum($subject, $level, 'Shared System Curriculum');
        $country = Country::factory()->create();

        $systemA = $this->educationSystemService()->createEducationSystem($this->admin, ['name' => 'System A', 'slug' => 'system-a-r']);
        $systemB = $this->educationSystemService()->createEducationSystem($this->admin, ['name' => 'System B', 'slug' => 'system-b-r']);

        foreach ([$systemA, $systemB] as $system) {
            $this->educationSystemService()->mapToCountry($this->admin, $system, $country);
            $this->educationSystemService()->mapToAcademicLevel($this->admin, $system, $level);
        }

        $instructor = $this->instructorTeaching($subject);
        $this->eligibilityService()->assign($this->admin, $instructor, $systemA, $curriculum);

        $contextA = $this->contextResolver()->resolveContext($country, $systemA, $level, $subject, $curriculum);
        $contextB = $this->contextResolver()->resolveContext($country, $systemB, $level, $subject, $curriculum);

        $this->assertTrue($this->eligibilityResolver()->isEligible($instructor, $contextA));
        $this->assertFalse($this->eligibilityResolver()->isEligible($instructor, $contextB));
    }

    /** Instructor eligibility must not require duplication when the same shared/international system spans multiple countries. */
    public function test_shared_education_system_across_two_countries_does_not_require_duplicate_eligibility(): void
    {
        $countryA = Country::factory()->create();
        $countryB = Country::factory()->create();
        $system = $this->educationSystemService()->createEducationSystem($this->admin, ['name' => 'IB Shared R', 'slug' => 'ib-shared-r']);
        $level = $this->level('ib-shared-r-level');
        $subject = $this->subject('ib-shared-r-subject');
        $curriculum = $this->publishedCurriculum($subject, $level, 'IB Shared R Curriculum');

        $this->educationSystemService()->mapToCountry($this->admin, $system, $countryA);
        $this->educationSystemService()->mapToCountry($this->admin, $system, $countryB);
        $this->educationSystemService()->mapToAcademicLevel($this->admin, $system, $level);
        $this->educationSystemService()->mapToCurriculum($this->admin, $system, $curriculum);

        $instructor = $this->instructorTeaching($subject);
        // A single eligibility row, no country_id at all.
        $this->eligibilityService()->assign($this->admin, $instructor, $system, $curriculum);

        $contextA = $this->contextResolver()->resolveContext($countryA, $system, $level, $subject, $curriculum);
        $contextB = $this->contextResolver()->resolveContext($countryB, $system, $level, $subject, $curriculum);

        $this->assertTrue($this->eligibilityResolver()->isEligible($instructor, $contextA));
        $this->assertTrue($this->eligibilityResolver()->isEligible($instructor, $contextB));
        $this->assertSame(1, InstructorCurriculumEligibility::query()->where('teacher_id', $instructor->id)->count());
    }

    public function test_eligible_education_systems_and_curricula_listing(): void
    {
        $fixture = $this->buildFixture('Listing');
        $instructor = $this->instructorTeaching($fixture['subject']);
        $this->eligibilityService()->assign($this->admin, $instructor, $fixture['system'], $fixture['curriculum']);

        $systems = $this->eligibilityResolver()->eligibleEducationSystemsFor($instructor);
        $this->assertTrue($systems->contains('id', $fixture['system']->id));

        $curricula = $this->eligibilityResolver()->eligibleCurriculaFor($instructor, $fixture['system']);
        $this->assertTrue($curricula->contains('id', $fixture['curriculum']->id));
    }
}
