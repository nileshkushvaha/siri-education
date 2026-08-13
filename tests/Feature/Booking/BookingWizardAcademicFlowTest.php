<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Enums\Weekday;
use App\Curriculum\Services\CurriculumService;
use App\Curriculum\Services\EducationSystemService;
use App\Curriculum\Services\InstructorAcademicEligibilityService;
use App\Livewire\Frontend\Booking\BookingWizard;
use App\Models\AcademicCategory;
use App\Models\AcademicLevel;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\Curriculum;
use App\Models\EducationSystem;
use App\Models\EducationSystemLevel;
use App\Models\Subject;
use App\Models\SubjectTopic;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Settings\FeatureSettings;
use Carbon\CarbonImmutable;
use Database\Seeders\AcademicPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 3.1 — the progressive Livewire BookingWizard academic UI (§5-§13):
 * Country server-resolution/anti-tampering, Education System -> Level ->
 * Subject -> Curriculum progression (one dynamic Level phase replacing the
 * old separate Academic Level + Grade phases), stale-state reset on every
 * upstream change, the locked-instructor narrowing/enforcement pair, the
 * missing-configuration UX, and dynamic per-system terminology.
 */
class BookingWizardAcademicFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AcademicPermissionSeeder::class);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole('manager');

        BookingType::factory()->create(['key' => 'free_demo', 'duration_minutes' => 30]);

        Livewire::component('frontend.booking.booking-wizard', BookingWizard::class);
    }

    private function educationSystemService(): EducationSystemService
    {
        return app(EducationSystemService::class);
    }

    private function curriculumService(): CurriculumService
    {
        return app(CurriculumService::class);
    }

    private function eligibilityService(): InstructorAcademicEligibilityService
    {
        return app(InstructorAcademicEligibilityService::class);
    }

    private function subject(string $slug): Subject
    {
        $category = AcademicCategory::query()->firstOrCreate(['slug' => 'wiz-general'], ['name' => 'Wizard General']);

        return Subject::create(['academic_category_id' => $category->id, 'name' => ucfirst($slug), 'slug' => $slug]);
    }

    private function academicLevel(string $slug): AcademicLevel
    {
        return AcademicLevel::create(['name' => ucfirst(str_replace('-', ' ', $slug)), 'slug' => $slug, 'min_grade' => 1, 'max_grade' => 12]);
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

    /** @return array{country: Country, system: EducationSystem, academicLevel: AcademicLevel, level: EducationSystemLevel, subject: Subject, curriculum: Curriculum} */
    private function buildFixture(string $prefix, ?Country $country = null, int $normalizedGrade = 10, string $levelTerm = 'Class'): array
    {
        $country ??= Country::factory()->create();
        $system = $this->educationSystemService()->createEducationSystem($this->admin, [
            'name' => "{$prefix} System",
            'slug' => strtolower($prefix).'-wiz-system',
            'level_term_singular' => $levelTerm,
            'level_term_plural' => $levelTerm.'es',
        ]);
        $academicLevel = $this->academicLevel(strtolower($prefix).'-wiz-band');
        $subject = $this->subject(strtolower($prefix).'-wiz-subject');
        $curriculum = $this->publishedCurriculum($subject, $academicLevel, "{$prefix} Wizard Curriculum");

        $this->educationSystemService()->mapToCountry($this->admin, $system, $country);
        $this->educationSystemService()->mapToAcademicLevel($this->admin, $system, $academicLevel);
        $this->educationSystemService()->mapToCurriculum($this->admin, $system, $curriculum);

        $level = $this->educationSystemService()->addLevel($this->admin, $system, [
            'academic_level_id' => $academicLevel->id,
            'value' => (string) $normalizedGrade,
            'display_label' => "{$levelTerm} {$normalizedGrade}",
            'normalized_grade' => $normalizedGrade,
        ]);

        return compact('country', 'system', 'academicLevel', 'level', 'subject', 'curriculum');
    }

    private function makeInstructor(Subject $subject): User
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');
        UserProfile::updateOrCreate(['user_id' => $instructor->id], ['instructor_status' => 'approved', 'profile_visibility' => 'public']);

        TeacherSubject::factory()->create([
            'teacher_id' => $instructor->id,
            'subject' => $subject->name,
            'subject_id' => $subject->id,
            'grade_from' => 1,
            'grade_to' => 12,
        ]);

        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $instructor->id])->forDay($day)->between('09:00:00', '17:00:00')->create();
        }

        return $instructor;
    }

    private function makeStudent(?Country $country = null): User
    {
        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');

        if ($country !== null) {
            UserProfile::updateOrCreate(['user_id' => $student->id], ['country_id' => $country->id]);
        }

        return $student;
    }

    private function enableGlobally(): void
    {
        $settings = app(FeatureSettings::class);
        $settings->country_academic_booking_enabled = true;
        $settings->save();
    }

    // ── Country resolution / anti-tampering ────────────────────────────────

    public function test_missing_student_country_blocks_the_academic_flow_with_actionable_message(): void
    {
        $this->enableGlobally();
        $student = $this->makeStudent(null);

        Livewire::actingAs($student)
            ->test('frontend.booking.booking-wizard')
            ->call('selectMode', 'free_demo')
            ->assertSet('academicFlowBlocked', true)
            ->assertSet('academicFlowActive', false)
            ->assertSet('step', 1)
            ->assertSet('banner', 'Please complete your profile country before booking a demo lesson.');
    }

    public function test_configured_country_activates_the_academic_flow_and_exposes_country_name_only(): void
    {
        $this->enableGlobally();
        $fixture = $this->buildFixture('CountryShow');
        $student = $this->makeStudent($fixture['country']);

        Livewire::actingAs($student)
            ->test('frontend.booking.booking-wizard')
            ->call('selectMode', 'free_demo')
            ->assertSet('academicFlowActive', true)
            ->assertSet('studentCountryId', $fixture['country']->id)
            ->assertSet('studentCountryName', $fixture['country']->name)
            ->assertSet('step', 2);
    }

    // ── Missing configuration UX (§13/§25) ──────────────────────────────────

    public function test_enabled_but_unconfigured_country_shows_unavailable_state_never_legacy_fallback(): void
    {
        $this->enableGlobally();
        $country = Country::factory()->create(); // no Education Systems mapped at all
        $student = $this->makeStudent($country);

        Livewire::actingAs($student)
            ->test('frontend.booking.booking-wizard')
            ->call('selectMode', 'free_demo')
            ->assertSet('academicFlowActive', true)
            ->assertSet('academicFlowUnavailable', true)
            ->assertSet('educationSystems', []);
    }

    public function test_system_with_no_configured_levels_shows_unavailable_state_never_a_1_to_12_fallback(): void
    {
        $this->enableGlobally();
        $country = Country::factory()->create();
        $system = $this->educationSystemService()->createEducationSystem($this->admin, ['name' => 'Empty System', 'slug' => 'empty-system']);
        $this->educationSystemService()->mapToCountry($this->admin, $system, $country);
        $student = $this->makeStudent($country);

        Livewire::actingAs($student)
            ->test('frontend.booking.booking-wizard')
            ->call('selectMode', 'free_demo')
            ->call('selectEducationSystem', $system->id)
            ->assertSet('levels', [])
            ->assertSet('grades', [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]); // legacy array untouched, never used to synthesize levels here
    }

    // ── Progressive selection + state reset (§7/§8/§12) ─────────────────────

    public function test_single_dynamic_level_phase_replaces_academic_level_plus_grade(): void
    {
        $this->enableGlobally();
        $fixture = $this->buildFixture('Single', normalizedGrade: 10);
        $student = $this->makeStudent($fixture['country']);

        $component = Livewire::actingAs($student)
            ->test('frontend.booking.booking-wizard')
            ->call('selectMode', 'free_demo')
            ->call('selectEducationSystem', $fixture['system']->id);

        // Exactly one level-selection phase exists in the academic flow —
        // no separate Academic Level phase, no separate Grade phase.
        self::assertSame(['mode', 'education_system', 'level', 'academic_subject', 'curriculum', 'date', 'time', 'review', 'confirmed'], $component->invade()->phases());

        $component
            ->assertSet('levels', fn (array $levels): bool => collect($levels)->contains('id', $fixture['level']->id))
            ->call('selectLevel', $fixture['level']->id)
            // Selecting the level implicitly resolves academic_level_id and normalized_grade.
            ->assertSet('grade', 10)
            ->assertSet('academicSubjects', fn (array $subjects): bool => collect($subjects)->contains('id', $fixture['subject']->id));
    }

    public function test_full_progressive_selection_walks_system_level_subject_curriculum(): void
    {
        $this->enableGlobally();
        $fixture = $this->buildFixture('Progressive');
        $student = $this->makeStudent($fixture['country']);

        Livewire::actingAs($student)
            ->test('frontend.booking.booking-wizard')
            ->call('selectMode', 'free_demo')
            ->call('selectEducationSystem', $fixture['system']->id)
            ->assertSet('educationSystemLevelId', null)
            ->assertSet('levels', fn (array $levels): bool => collect($levels)->contains('id', $fixture['level']->id))
            ->call('selectLevel', $fixture['level']->id)
            ->assertSet('academicSubjects', fn (array $subjects): bool => collect($subjects)->contains('id', $fixture['subject']->id))
            ->call('selectAcademicSubject', $fixture['subject']->id)
            ->assertSet('subject', $fixture['subject']->name)
            ->assertSet('curricula', fn (array $curricula): bool => collect($curricula)->contains('id', $fixture['curriculum']->id))
            ->call('selectCurriculum', $fixture['curriculum']->id)
            ->assertSet('curriculumId', $fixture['curriculum']->id)
            ->assertSet('step', fn (int $step): bool => $step > 1);
    }

    public function test_changing_education_system_clears_downstream_selection(): void
    {
        $this->enableGlobally();
        $country = Country::factory()->create();
        $fixtureA = $this->buildFixture('ResetA', $country);
        $fixtureB = $this->buildFixture('ResetB', $country);
        $student = $this->makeStudent($country);

        $component = Livewire::actingAs($student)
            ->test('frontend.booking.booking-wizard')
            ->call('selectMode', 'free_demo')
            ->call('selectEducationSystem', $fixtureA['system']->id)
            ->call('selectLevel', $fixtureA['level']->id)
            ->call('selectAcademicSubject', $fixtureA['subject']->id)
            ->call('selectCurriculum', $fixtureA['curriculum']->id)
            ->assertSet('curriculumId', $fixtureA['curriculum']->id);

        // Re-entering the education_system step and picking a different
        // system must clear every downstream selection (§8).
        $component
            ->call('selectEducationSystem', $fixtureB['system']->id)
            ->assertSet('educationSystemLevelId', null)
            ->assertSet('academicSubjectId', null)
            ->assertSet('curriculumId', null)
            ->assertSet('levels', fn (array $levels): bool => collect($levels)->contains('id', $fixtureB['level']->id) && ! collect($levels)->contains('id', $fixtureA['level']->id));
    }

    public function test_changing_level_clears_subject_and_curriculum(): void
    {
        $this->enableGlobally();
        $country = Country::factory()->create();
        $system = $this->educationSystemService()->createEducationSystem($this->admin, ['name' => 'Shared Sys', 'slug' => 'shared-sys-reset']);
        $this->educationSystemService()->mapToCountry($this->admin, $system, $country);

        $academicLevelA = $this->academicLevel('reset-band-a');
        $academicLevelB = $this->academicLevel('reset-band-b');
        $this->educationSystemService()->mapToAcademicLevel($this->admin, $system, $academicLevelA);
        $this->educationSystemService()->mapToAcademicLevel($this->admin, $system, $academicLevelB);

        $levelA = $this->educationSystemService()->addLevel($this->admin, $system, [
            'academic_level_id' => $academicLevelA->id, 'value' => '9', 'display_label' => 'Class 9', 'normalized_grade' => 9,
        ]);
        $levelB = $this->educationSystemService()->addLevel($this->admin, $system, [
            'academic_level_id' => $academicLevelB->id, 'value' => '10', 'display_label' => 'Class 10', 'normalized_grade' => 10,
        ]);

        $subjectA = $this->subject('reset-subject-a');
        $curriculumA = $this->publishedCurriculum($subjectA, $academicLevelA, 'Reset Curriculum A');
        $this->educationSystemService()->mapToCurriculum($this->admin, $system, $curriculumA);

        $student = $this->makeStudent($country);

        Livewire::actingAs($student)
            ->test('frontend.booking.booking-wizard')
            ->call('selectMode', 'free_demo')
            ->call('selectEducationSystem', $system->id)
            ->call('selectLevel', $levelA->id)
            ->call('selectAcademicSubject', $subjectA->id)
            ->call('selectCurriculum', $curriculumA->id)
            ->assertSet('curriculumId', $curriculumA->id)
            ->call('selectLevel', $levelB->id)
            ->assertSet('academicSubjectId', null)
            ->assertSet('curriculumId', null)
            ->assertSet('curricula', []);
    }

    public function test_level_with_no_normalized_grade_is_refused_as_unsupported(): void
    {
        $this->enableGlobally();
        $country = Country::factory()->create();
        $system = $this->educationSystemService()->createEducationSystem($this->admin, ['name' => 'Non Numeric Sys', 'slug' => 'non-numeric-sys']);
        $this->educationSystemService()->mapToCountry($this->admin, $system, $country);
        $academicLevel = $this->academicLevel('undergrad-band');
        $this->educationSystemService()->mapToAcademicLevel($this->admin, $system, $academicLevel);
        $level = $this->educationSystemService()->addLevel($this->admin, $system, [
            'academic_level_id' => $academicLevel->id, 'value' => 'ug', 'display_label' => 'Undergraduate', 'normalized_grade' => null,
        ]);
        $student = $this->makeStudent($country);

        Livewire::actingAs($student)
            ->test('frontend.booking.booking-wizard')
            ->call('selectMode', 'free_demo')
            ->call('selectEducationSystem', $system->id)
            ->call('selectLevel', $level->id)
            ->assertSet('educationSystemLevelId', null)
            ->assertSet('banner', 'This level is not currently supported for demo booking. Please select a different level.');
    }

    // ── Dynamic terminology (§13/§36) ────────────────────────────────────────

    public function test_india_style_system_uses_class_terminology(): void
    {
        $this->enableGlobally();
        $fixture = $this->buildFixture('India', normalizedGrade: 10, levelTerm: 'Class');
        $student = $this->makeStudent($fixture['country']);

        Livewire::actingAs($student)
            ->test('frontend.booking.booking-wizard')
            ->call('selectMode', 'free_demo')
            ->call('selectEducationSystem', $fixture['system']->id)
            ->assertSee('Choose a Class')
            ->call('selectLevel', $fixture['level']->id)
            ->assertSet('grade', 10);
    }

    public function test_us_style_system_uses_grade_terminology(): void
    {
        $this->enableGlobally();
        $fixture = $this->buildFixture('US', normalizedGrade: 10, levelTerm: 'Grade');
        $student = $this->makeStudent($fixture['country']);

        Livewire::actingAs($student)
            ->test('frontend.booking.booking-wizard')
            ->call('selectMode', 'free_demo')
            ->call('selectEducationSystem', $fixture['system']->id)
            ->assertSee('Choose a Grade');
    }

    public function test_uk_style_system_uses_year_terminology(): void
    {
        $this->enableGlobally();
        $fixture = $this->buildFixture('UK', normalizedGrade: 10, levelTerm: 'Year');
        $student = $this->makeStudent($fixture['country']);

        Livewire::actingAs($student)
            ->test('frontend.booking.booking-wizard')
            ->call('selectMode', 'free_demo')
            ->call('selectEducationSystem', $fixture['system']->id)
            ->assertSee('Choose a Year');
    }

    public function test_unconfigured_terminology_falls_back_to_generic_level(): void
    {
        $this->enableGlobally();
        $country = Country::factory()->create();
        $system = $this->educationSystemService()->createEducationSystem($this->admin, ['name' => 'No Term System', 'slug' => 'no-term-system']);
        $this->educationSystemService()->mapToCountry($this->admin, $system, $country);
        $student = $this->makeStudent($country);

        Livewire::actingAs($student)
            ->test('frontend.booking.booking-wizard')
            ->call('selectMode', 'free_demo')
            ->call('selectEducationSystem', $system->id)
            ->assertSee('Choose a Level');
    }

    // ── Locked instructor flow (§9) ─────────────────────────────────────────

    public function test_locked_instructor_narrows_education_systems_to_their_own_eligibility(): void
    {
        $this->enableGlobally();
        $country = Country::factory()->create();
        $eligibleFixture = $this->buildFixture('LockedEligible', $country);
        $ineligibleFixture = $this->buildFixture('LockedIneligible', $country);

        $instructor = $this->makeInstructor($eligibleFixture['subject']);
        TeacherSubject::factory()->create([
            'teacher_id' => $instructor->id,
            'subject' => $ineligibleFixture['subject']->name,
            'subject_id' => $ineligibleFixture['subject']->id,
            'grade_from' => 1,
            'grade_to' => 12,
        ]);
        $this->eligibilityService()->assign($this->admin, $instructor, $eligibleFixture['system'], $eligibleFixture['curriculum']);
        // Deliberately never granted eligibility for $ineligibleFixture.

        $student = $this->makeStudent($country);

        Livewire::actingAs($student)
            ->withQueryParams(['instructor' => $instructor->slug, 'type' => 'free_demo'])
            ->test('frontend.booking.booking-wizard')
            ->assertSet('lockedInstructorId', $instructor->id)
            ->assertSet('educationSystems', fn (array $systems): bool => collect($systems)->contains('id', $eligibleFixture['system']->id)
                && ! collect($systems)->contains('id', $ineligibleFixture['system']->id));
    }

    public function test_tampered_selection_of_a_system_outside_locked_instructor_eligibility_is_ignored(): void
    {
        $this->enableGlobally();
        $country = Country::factory()->create();
        $eligibleFixture = $this->buildFixture('TamperEligible', $country);
        $ineligibleFixture = $this->buildFixture('TamperIneligible', $country);

        $instructor = $this->makeInstructor($eligibleFixture['subject']);
        $this->eligibilityService()->assign($this->admin, $instructor, $eligibleFixture['system'], $eligibleFixture['curriculum']);

        $student = $this->makeStudent($country);

        Livewire::actingAs($student)
            ->withQueryParams(['instructor' => $instructor->slug, 'type' => 'free_demo'])
            ->test('frontend.booking.booking-wizard')
            // Crafted call: an id never offered to this locked instructor.
            ->call('selectEducationSystem', $ineligibleFixture['system']->id)
            ->assertSet('educationSystemId', null);
    }

    public function test_locked_instructor_id_survives_and_final_submit_still_enforces_eligibility(): void
    {
        $this->enableGlobally();
        $fixture = $this->buildFixture('LockedSubmit');
        $instructor = $this->makeInstructor($fixture['subject']); // TeacherSubject matches, never granted eligibility
        $student = $this->makeStudent($fixture['country']);

        Livewire::actingAs($student)
            ->withQueryParams(['instructor' => $instructor->slug, 'type' => 'free_demo'])
            ->test('frontend.booking.booking-wizard')
            ->assertSet('lockedInstructorId', $instructor->id)
            // Not eligible -> excluded from candidate narrowing at the
            // Education System step itself (never offered as an option).
            ->assertSet('educationSystems', []);

        $this->assertDatabaseCount('bookings', 0);
    }

    // ── End-to-end submit ────────────────────────────────────────────────────

    public function test_end_to_end_academic_demo_submission_creates_booking_and_snapshot(): void
    {
        $this->enableGlobally();
        $fixture = $this->buildFixture('E2E', normalizedGrade: 6, levelTerm: 'Class');
        $instructor = $this->makeInstructor($fixture['subject']);
        $this->eligibilityService()->assign($this->admin, $instructor, $fixture['system'], $fixture['curriculum']);
        $student = $this->makeStudent($fixture['country']);

        $slot = CarbonImmutable::now('UTC')->addDays(3)->setTime(10, 0);

        $component = Livewire::actingAs($student)
            ->test('frontend.booking.booking-wizard')
            ->call('selectMode', 'free_demo')
            ->call('selectEducationSystem', $fixture['system']->id)
            ->call('selectLevel', $fixture['level']->id)
            ->call('selectAcademicSubject', $fixture['subject']->id)
            ->call('selectCurriculum', $fixture['curriculum']->id);

        for ($month = CarbonImmutable::now('UTC')->startOfMonth(); $month->lt($slot->startOfMonth()); $month = $month->addMonthNoOverflow()) {
            $component->call('nextMonth');
        }

        $component
            ->call('selectDate', $slot->toDateString())
            ->call('selectSlot', $slot->toIso8601String())
            ->call('submit');

        $this->assertDatabaseCount('bookings', 1);
        $booking = Booking::query()->firstOrFail();
        $this->assertNotNull($booking->academicContext);
        $this->assertSame($fixture['curriculum']->id, $booking->academicContext->curriculum_id);
        $this->assertSame($fixture['level']->id, $booking->academicContext->education_system_level_id);
        $this->assertSame('Class 6', $booking->academicContext->level_display);
        $this->assertSame(6, $booking->academicContext->normalized_grade);
        $this->assertSame(6, $booking->meta['grade']);
    }
}
