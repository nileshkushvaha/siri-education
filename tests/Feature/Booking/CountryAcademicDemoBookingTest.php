<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Contracts\BookingAcademicContextRepositoryInterface;
use App\Booking\Contracts\WizardBookingServiceInterface;
use App\Booking\DTOs\AssignmentCriteriaData;
use App\Booking\DTOs\BookingAcademicContextData;
use App\Booking\DTOs\WizardBookingData;
use App\Booking\Enums\Weekday;
use App\Booking\Exceptions\BookingException;
use App\Booking\Exceptions\FreeDemoAlreadyUsedException;
use App\Booking\Repositories\TeacherCandidateRepository;
use App\Booking\Services\DemoAcademicContextResolver;
use App\Curriculum\Services\AcademicContextResolver;
use App\Curriculum\Services\CurriculumService;
use App\Curriculum\Services\EducationSystemService;
use App\Curriculum\Services\InstructorAcademicEligibilityService;
use App\Models\AcademicCategory;
use App\Models\AcademicLevel;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\Curriculum;
use App\Models\EducationSystem;
use App\Models\EducationSystemLevel;
use App\Models\InstructorCurriculumEligibility;
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
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 3 / 3.1 — country-aware academic Demo booking. Covers feature
 * flag behavior, academic candidate filtering, immutable snapshot
 * creation (now carrying EducationSystemLevel fields instead of the old
 * hardcoded "Grade %d" string), transaction atomicity, legacy
 * compatibility, and the (unchanged) one-free-demo lifetime rule under
 * academic variation. Fixture conventions mirror
 * AcademicContextResolverTest / InstructorAcademicEligibilityResolverTest.
 */
class CountryAcademicDemoBookingTest extends TestCase
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
    }

    // ── Helpers ───────────────────────────────────────────────────────────

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
        $category = AcademicCategory::query()->firstOrCreate(['slug' => 'p3-general'], ['name' => 'P3 General']);

        return Subject::create([
            'academic_category_id' => $category->id,
            'name' => ucfirst($slug),
            'slug' => $slug,
        ]);
    }

    private function academicLevel(string $slug): AcademicLevel
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

    /** @return array{country: Country, system: EducationSystem, academicLevel: AcademicLevel, level: EducationSystemLevel, subject: Subject, curriculum: Curriculum} */
    private function buildFixture(string $prefix, ?int $normalizedGrade = 10): array
    {
        $country = Country::factory()->create();
        $system = $this->educationSystemService()->createEducationSystem($this->admin, [
            'name' => "{$prefix} System",
            'slug' => strtolower($prefix).'-p3-system',
            'level_term_singular' => 'Class',
            'level_term_plural' => 'Classes',
        ]);
        $academicLevel = $this->academicLevel(strtolower($prefix).'-p3-band');
        $subject = $this->subject(strtolower($prefix).'-p3-subject');
        $curriculum = $this->publishedCurriculum($subject, $academicLevel, "{$prefix} P3 Curriculum");

        $this->educationSystemService()->mapToCountry($this->admin, $system, $country);
        $this->educationSystemService()->mapToAcademicLevel($this->admin, $system, $academicLevel);
        $this->educationSystemService()->mapToCurriculum($this->admin, $system, $curriculum);

        $level = $this->educationSystemService()->addLevel($this->admin, $system, [
            'academic_level_id' => $academicLevel->id,
            'value' => $normalizedGrade === null ? 'ug' : (string) $normalizedGrade,
            'display_label' => $normalizedGrade === null ? 'Undergraduate' : "Class {$normalizedGrade}",
            'normalized_grade' => $normalizedGrade,
        ]);

        return compact('country', 'system', 'academicLevel', 'level', 'subject', 'curriculum');
    }

    private function makeInstructor(Subject $subject, int $gradeFrom = 1, int $gradeTo = 12): User
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');
        UserProfile::updateOrCreate(['user_id' => $instructor->id], ['instructor_status' => 'approved']);

        TeacherSubject::factory()->create([
            'teacher_id' => $instructor->id,
            'subject' => $subject->name,
            'subject_id' => $subject->id,
            'grade_from' => $gradeFrom,
            'grade_to' => $gradeTo,
        ]);

        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()
                ->state(['teacher_id' => $instructor->id])
                ->forDay($day)
                ->between('09:00:00', '17:00:00')
                ->create();
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
        $settings->demo_lessons_enabled = true;
        $settings->save();
    }

    private function slot(int $daysAhead): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->addDays($daysAhead)->setTime(10, 0);
    }

    /** @return array{country: Country, system: EducationSystem, academicLevel: AcademicLevel, level: EducationSystemLevel, subject: Subject, curriculum: Curriculum, student: User, instructor: User} */
    private function eligibleScenario(string $prefix, ?int $normalizedGrade = 10): array
    {
        $fixture = $this->buildFixture($prefix, $normalizedGrade);
        $instructor = $this->makeInstructor($fixture['subject']);
        $this->eligibilityService()->assign($this->admin, $instructor, $fixture['system'], $fixture['curriculum']);
        $student = $this->makeStudent($fixture['country']);

        return [...$fixture, 'student' => $student, 'instructor' => $instructor];
    }

    private function wizardData(array $s, int $daysAhead, ?int $teacherId = null): WizardBookingData
    {
        return new WizardBookingData(
            typeKey: 'free_demo',
            subject: $s['subject']->name,
            grade: 5, // legacy-compat placeholder — overridden server-side from the resolved EducationSystemLevel.normalized_grade when academic context resolves.
            startsAt: $this->slot($daysAhead),
            timezone: 'UTC',
            teacherId: $teacherId,
            educationSystemId: $s['system']->id,
            educationSystemLevelId: $s['level']->id,
            subjectId: $s['subject']->id,
            curriculumId: $s['curriculum']->id,
        );
    }

    // ── A. Feature flag ──────────────────────────────────────────────────

    public function test_country_aware_academic_flow_has_no_independent_global_switch(): void
    {
        $s = $this->eligibleScenario('AlwaysCountryAware');

        $this->actingAs($s['student']);
        $booking = app(WizardBookingServiceInterface::class)->book($this->wizardData($s, 3));

        $this->assertNotNull($booking->academicContext);
    }

    public function test_flag_enabled_and_configured_produces_academic_snapshot(): void
    {
        $this->enableGlobally();
        $s = $this->eligibleScenario('EnabledOk');

        $this->actingAs($s['student']);
        $booking = app(WizardBookingServiceInterface::class)->book($this->wizardData($s, 3));

        $this->assertDatabaseCount('booking_academic_contexts', 1);
        $this->assertNotNull($booking->academicContext);
        $this->assertSame($s['curriculum']->id, $booking->academicContext->curriculum_id);
    }

    public function test_flag_enabled_but_incomplete_selection_never_falls_back_to_legacy(): void
    {
        $this->enableGlobally();
        $fixture = $this->buildFixture('Incomplete');
        $instructor = $this->makeInstructor($fixture['subject']);
        $student = $this->makeStudent($fixture['country']);

        $this->actingAs($student);

        $data = new WizardBookingData(
            typeKey: 'free_demo',
            subject: $fixture['subject']->name,
            grade: 5,
            startsAt: $this->slot(3),
            timezone: 'UTC',
            teacherId: $instructor->id,
            // Deliberately missing curriculumId — must reject, never
            // silently book against legacy free-text subject/grade.
            educationSystemId: $fixture['system']->id,
            educationSystemLevelId: $fixture['level']->id,
            subjectId: $fixture['subject']->id,
            curriculumId: null,
        );

        $this->expectException(BookingException::class);
        try {
            app(WizardBookingServiceInterface::class)->book($data);
        } finally {
            $this->assertDatabaseCount('bookings', 0);
        }
    }

    public function test_old_country_override_cannot_disable_country_aware_demo_booking(): void
    {
        $this->enableGlobally();
        $scenario = $this->eligibleScenario('CountryOverrideIgnored');
        $scenario['country']->update(['feature_flags' => ['country_academic_booking' => false]]);

        $this->actingAs($scenario['student']);
        $booking = app(WizardBookingServiceInterface::class)->book($this->wizardData($scenario, 3));

        $this->assertNotNull($booking->refresh()->academicContext);
    }

    // ── B. Resolver tampering surfaces as a booking rejection ─────────────

    public function test_curriculum_for_wrong_subject_is_rejected_at_booking_time(): void
    {
        $this->enableGlobally();
        $s = $this->eligibleScenario('Tamper');
        $otherSubject = $this->subject('tamper-other-subject');

        $this->actingAs($s['student']);

        $data = new WizardBookingData(
            typeKey: 'free_demo',
            subject: $otherSubject->name,
            grade: 5,
            startsAt: $this->slot(3),
            timezone: 'UTC',
            teacherId: $s['instructor']->id,
            educationSystemId: $s['system']->id,
            educationSystemLevelId: $s['level']->id,
            subjectId: $otherSubject->id,
            curriculumId: $s['curriculum']->id, // belongs to $s['subject'], not $otherSubject
        );

        $this->expectException(BookingException::class);
        app(WizardBookingServiceInterface::class)->book($data);
    }

    // ── C. Candidate filtering (CRITICAL) ──────────────────────────────────

    public function test_academically_ineligible_candidate_is_excluded_only_eligible_one_remains(): void
    {
        $fixture = $this->buildFixture('Candidates');
        $instructorA = $this->makeInstructor($fixture['subject']); // TeacherSubject matches, no eligibility
        $instructorB = $this->makeInstructor($fixture['subject']); // TeacherSubject matches, eligible
        $this->eligibilityService()->assign($this->admin, $instructorB, $fixture['system'], $fixture['curriculum']);

        $academicContext = app(AcademicContextResolver::class)->resolveContextForLevel(
            $fixture['country'],
            $fixture['system'],
            $fixture['level'],
            $fixture['subject'],
            $fixture['curriculum'],
        );

        $criteria = new AssignmentCriteriaData(
            typeKey: 'free_demo',
            subject: $fixture['subject']->name,
            grade: $fixture['level']->normalized_grade,
            startsAt: $this->slot(3),
            durationMinutes: 30,
            academicContext: $academicContext,
        );

        $candidates = app(TeacherCandidateRepository::class)->eligible($criteria);

        $this->assertFalse($candidates->contains('id', $instructorA->id));
        $this->assertTrue($candidates->contains('id', $instructorB->id));
        $this->assertSame(1, $candidates->count());
    }

    public function test_without_academic_context_both_teacher_subject_matches_remain_candidates(): void
    {
        $fixture = $this->buildFixture('NoContext');
        $instructorA = $this->makeInstructor($fixture['subject']);
        $instructorB = $this->makeInstructor($fixture['subject']);

        $criteria = new AssignmentCriteriaData(
            typeKey: 'free_demo',
            subject: $fixture['subject']->name,
            grade: 5,
            startsAt: $this->slot(3),
            durationMinutes: 30,
        );

        $candidates = app(TeacherCandidateRepository::class)->eligible($criteria);

        $this->assertTrue($candidates->contains('id', $instructorA->id));
        $this->assertTrue($candidates->contains('id', $instructorB->id));
    }

    // ── D. Locked instructor flow ──────────────────────────────────────────

    public function test_locked_incompatible_instructor_is_rejected_at_final_submit(): void
    {
        $this->enableGlobally();
        $fixture = $this->buildFixture('Locked');
        $instructor = $this->makeInstructor($fixture['subject']); // never granted eligibility
        $student = $this->makeStudent($fixture['country']);

        $this->actingAs($student);

        $data = $this->wizardData([...$fixture, 'student' => $student, 'instructor' => $instructor], 3, teacherId: $instructor->id);

        $this->expectException(BookingException::class);
        try {
            app(WizardBookingServiceInterface::class)->book($data);
        } finally {
            $this->assertDatabaseCount('bookings', 0);
        }
    }

    public function test_locked_compatible_instructor_succeeds(): void
    {
        $this->enableGlobally();
        $s = $this->eligibleScenario('LockedOk');

        $this->actingAs($s['student']);
        $booking = app(WizardBookingServiceInterface::class)->book($this->wizardData($s, 3, teacherId: $s['instructor']->id));

        $this->assertSame($s['instructor']->id, $booking->instructor_id);
        $this->assertNotNull($booking->academicContext);
    }

    // ── E/F. Snapshot creation + immutability ──────────────────────────────

    public function test_successful_booking_creates_exactly_one_booking_and_one_snapshot_with_correct_values(): void
    {
        $this->enableGlobally();
        $s = $this->eligibleScenario('SnapshotCreate', 10);

        $this->actingAs($s['student']);
        $booking = app(WizardBookingServiceInterface::class)->book($this->wizardData($s, 3));

        $this->assertDatabaseCount('bookings', 1);
        $this->assertDatabaseCount('booking_academic_contexts', 1);

        $snapshot = $booking->academicContext;
        $this->assertSame($booking->id, $snapshot->booking_id);
        $this->assertSame($s['country']->id, $snapshot->country_id);
        $this->assertSame($s['system']->id, $snapshot->education_system_id);
        $this->assertSame($s['academicLevel']->id, $snapshot->academic_level_id);
        $this->assertSame($s['subject']->id, $snapshot->subject_id);
        $this->assertSame($s['curriculum']->id, $snapshot->curriculum_id);
        $this->assertSame($s['country']->name, $snapshot->country_name);
        $this->assertSame($s['subject']->name, $snapshot->subject_name);
        $this->assertSame(1, $snapshot->curriculum_version_number);

        // Phase 3.1 — the student-facing level snapshot, not "Grade %d".
        $this->assertSame($s['level']->id, $snapshot->education_system_level_id);
        $this->assertSame('Class', $snapshot->level_term);
        $this->assertSame('10', $snapshot->level_value);
        $this->assertSame('Class 10', $snapshot->level_display);
        $this->assertSame(10, $snapshot->normalized_grade);
        $this->assertSame(10, $booking->meta['grade']);
    }

    public function test_snapshot_is_historical_and_survives_master_renames_and_new_curriculum_versions(): void
    {
        $this->enableGlobally();
        $s = $this->eligibleScenario('Historical');

        $this->actingAs($s['student']);
        $booking = app(WizardBookingServiceInterface::class)->book($this->wizardData($s, 3));
        $snapshot = $booking->academicContext;
        $this->assertSame(1, $snapshot->curriculum_version_number);

        // Rename masters after booking.
        $s['subject']->update(['name' => 'Renamed Subject']);
        $s['system']->update(['name' => 'Renamed System']);

        $snapshot->refresh();
        $this->assertNotSame('Renamed Subject', $snapshot->subject_name);
        $this->assertNotSame('Renamed System', $snapshot->education_system_name);

        // Publish v2 of the curriculum.
        $v1 = $s['curriculum']->latestPublishedVersion();
        $v2 = $this->curriculumService()->createNewVersion($this->admin, $s['curriculum']->refresh(), $v1);
        $this->curriculumService()->publish($this->admin, $v2);

        // The existing booking's snapshot must remain on v1.
        $snapshot->refresh();
        $this->assertSame(1, $snapshot->curriculum_version_number);

        // A new booking (different instructor to avoid the lifetime rule) picks up v2.
        $instructor2 = $this->makeInstructor($s['subject']);
        $this->eligibilityService()->assign($this->admin, $instructor2, $s['system'], $s['curriculum']->refresh());

        $booking2 = app(WizardBookingServiceInterface::class)->book($this->wizardData([...$s, 'instructor' => $instructor2], 5, teacherId: $instructor2->id));
        $this->assertSame(2, $booking2->academicContext->curriculum_version_number);
    }

    /** §40 — an admin renaming an EducationSystemLevel's display label after booking must not rewrite that booking's historical display. */
    public function test_renaming_the_level_after_booking_does_not_change_historical_display(): void
    {
        $this->enableGlobally();
        $s = $this->eligibleScenario('RenameLevel', 10);

        $this->actingAs($s['student']);
        $booking = app(WizardBookingServiceInterface::class)->book($this->wizardData($s, 3));
        $this->assertSame('Class 10', $booking->academicContext->level_display);

        $this->educationSystemService()->updateLevel($this->admin, $s['level'], ['display_label' => 'Standard 10']);

        $booking->academicContext->refresh();
        $this->assertSame('Class 10', $booking->academicContext->level_display);

        // A new booking (different instructor) picks up the renamed label.
        $instructor2 = $this->makeInstructor($s['subject']);
        $this->eligibilityService()->assign($this->admin, $instructor2, $s['system'], $s['curriculum']);
        $booking2 = app(WizardBookingServiceInterface::class)->book($this->wizardData([...$s, 'instructor' => $instructor2], 5, teacherId: $instructor2->id));
        $this->assertSame('Standard 10', $booking2->academicContext->level_display);
    }

    public function test_snapshot_is_immutable_against_direct_update_and_hard_delete(): void
    {
        $this->enableGlobally();
        $s = $this->eligibleScenario('Immutable');

        $this->actingAs($s['student']);
        $booking = app(WizardBookingServiceInterface::class)->book($this->wizardData($s, 3));
        $snapshot = $booking->academicContext;

        $this->expectException(\Throwable::class);
        $snapshot->update(['subject_name' => 'Hacked']);
    }

    public function test_snapshot_hard_delete_is_prevented(): void
    {
        $this->enableGlobally();
        $s = $this->eligibleScenario('ImmutableDelete');

        $this->actingAs($s['student']);
        $booking = app(WizardBookingServiceInterface::class)->book($this->wizardData($s, 3));
        $snapshot = $booking->academicContext;

        $this->expectException(\Throwable::class);
        $snapshot->delete();
    }

    public function test_duplicate_snapshot_creation_for_the_same_booking_is_idempotent(): void
    {
        $this->enableGlobally();
        $s = $this->eligibleScenario('Idempotent');

        $this->actingAs($s['student']);
        $booking = app(WizardBookingServiceInterface::class)->book($this->wizardData($s, 3));

        $snapshot = $booking->academicContext;
        $repository = app(BookingAcademicContextRepositoryInterface::class);
        $second = $repository->createFor($booking, new BookingAcademicContextData(
            countryId: $snapshot->country_id,
            countryCode: $snapshot->country_code,
            countryName: $snapshot->country_name,
            educationSystemId: $snapshot->education_system_id,
            educationSystemCode: $snapshot->education_system_code,
            educationSystemName: $snapshot->education_system_name,
            academicLevelId: $snapshot->academic_level_id,
            academicLevelName: $snapshot->academic_level_name,
            educationSystemLevelId: $snapshot->education_system_level_id,
            levelTerm: $snapshot->level_term,
            levelValue: $snapshot->level_value,
            levelDisplay: $snapshot->level_display,
            normalizedGrade: $snapshot->normalized_grade,
            subjectId: $snapshot->subject_id,
            subjectName: $snapshot->subject_name,
            subjectSlug: $snapshot->subject_slug,
            curriculumId: $snapshot->curriculum_id,
            curriculumName: $snapshot->curriculum_name,
            curriculumSlug: $snapshot->curriculum_slug,
            curriculumVersionId: $snapshot->curriculum_version_id,
            curriculumVersionNumber: $snapshot->curriculum_version_number,
        ));

        $this->assertSame($snapshot->id, $second->id);
        $this->assertDatabaseCount('booking_academic_contexts', 1);
    }

    // ── G. Transaction rollback ──────────────────────────────────────────

    public function test_snapshot_persistence_failure_rolls_back_the_entire_booking(): void
    {
        $this->enableGlobally();
        $s = $this->eligibleScenario('Rollback');

        $this->mock(BookingAcademicContextRepositoryInterface::class, function ($mock): void {
            $mock->shouldReceive('createFor')->andThrow(new \RuntimeException('simulated snapshot failure'));
        });

        $this->actingAs($s['student']);

        try {
            app(WizardBookingServiceInterface::class)->book($this->wizardData($s, 3));
            $this->fail('Expected the simulated snapshot failure to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated snapshot failure', $e->getMessage());
        }

        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('booking_academic_contexts', 0);
        $this->assertDatabaseCount('booking_meetings', 0);
    }

    // ── H. Legacy compatibility ─────────────────────────────────────────────

    public function test_legacy_booking_without_snapshot_loads_and_meta_remains_readable(): void
    {
        $demoType = BookingType::query()->where('key', 'free_demo')->firstOrFail();
        $legacy = Booking::factory()->for($demoType, 'type')->create([
            'meta' => ['subject' => 'maths', 'grade' => 6],
        ]);

        $this->assertNull($legacy->academicContext);
        $this->assertSame('maths', $legacy->meta['subject']);
        $this->assertSame(6, $legacy->meta['grade']);
    }

    // ── I. Demo lifetime rule remains unchanged under academic variation ──

    public function test_lifetime_rule_still_blocks_second_demo_with_same_instructor_despite_different_academic_context(): void
    {
        $this->enableGlobally();
        $country = Country::factory()->create();

        $systemA = $this->educationSystemService()->createEducationSystem($this->admin, ['name' => 'Lifetime System A', 'slug' => 'lifetime-a-system']);
        $academicLevelA = $this->academicLevel('lifetime-a-band');
        $subjectA = $this->subject('lifetime-a-subject');
        $curriculumA = $this->publishedCurriculum($subjectA, $academicLevelA, 'Lifetime A Curriculum');
        $this->educationSystemService()->mapToCountry($this->admin, $systemA, $country);
        $this->educationSystemService()->mapToAcademicLevel($this->admin, $systemA, $academicLevelA);
        $this->educationSystemService()->mapToCurriculum($this->admin, $systemA, $curriculumA);
        $levelA = $this->educationSystemService()->addLevel($this->admin, $systemA, [
            'academic_level_id' => $academicLevelA->id, 'value' => '10', 'display_label' => 'Class 10', 'normalized_grade' => 10,
        ]);
        $fixtureA = ['country' => $country, 'system' => $systemA, 'academicLevel' => $academicLevelA, 'level' => $levelA, 'subject' => $subjectA, 'curriculum' => $curriculumA];

        $systemB = $this->educationSystemService()->createEducationSystem($this->admin, ['name' => 'Lifetime System B', 'slug' => 'lifetime-b-system']);
        $academicLevelB = $this->academicLevel('lifetime-b-band');
        $subjectB = $this->subject('lifetime-b-subject');
        $curriculumB = $this->publishedCurriculum($subjectB, $academicLevelB, 'Lifetime B Curriculum');
        $this->educationSystemService()->mapToCountry($this->admin, $systemB, $country);
        $this->educationSystemService()->mapToAcademicLevel($this->admin, $systemB, $academicLevelB);
        $this->educationSystemService()->mapToCurriculum($this->admin, $systemB, $curriculumB);
        $levelB = $this->educationSystemService()->addLevel($this->admin, $systemB, [
            'academic_level_id' => $academicLevelB->id, 'value' => '10', 'display_label' => 'Class 10', 'normalized_grade' => 10,
        ]);
        $fixtureB = ['country' => $country, 'system' => $systemB, 'academicLevel' => $academicLevelB, 'level' => $levelB, 'subject' => $subjectB, 'curriculum' => $curriculumB];

        // Instructor teaches both subjects and is eligible for both curricula.
        $instructor = $this->makeInstructor($fixtureA['subject']);
        TeacherSubject::factory()->create([
            'teacher_id' => $instructor->id,
            'subject' => $fixtureB['subject']->name,
            'subject_id' => $fixtureB['subject']->id,
            'grade_from' => 1,
            'grade_to' => 12,
        ]);
        $this->eligibilityService()->assign($this->admin, $instructor, $fixtureA['system'], $fixtureA['curriculum']);
        $this->eligibilityService()->assign($this->admin, $instructor, $fixtureB['system'], $fixtureB['curriculum']);

        $student = $this->makeStudent($country);
        $this->actingAs($student);

        app(WizardBookingServiceInterface::class)->book($this->wizardData([...$fixtureA, 'instructor' => $instructor], 3, teacherId: $instructor->id));

        $this->expectException(FreeDemoAlreadyUsedException::class);
        try {
            app(WizardBookingServiceInterface::class)->book($this->wizardData([...$fixtureB, 'instructor' => $instructor], 7, teacherId: $instructor->id));
        } finally {
            $this->assertDatabaseCount('bookings', 1);
        }
    }

    // ── J. Normalized grade / candidate compatibility (§38/§39) ────────────

    public function test_instructor_range_covering_the_levels_normalized_grade_passes(): void
    {
        $this->enableGlobally();
        $fixture = $this->buildFixture('CoveredGrade', 10);
        $instructor = $this->makeInstructor($fixture['subject'], 9, 12); // covers Class 10
        // InstructorCurriculumEligibilityService::assign() separately
        // requires TeacherSubject to cover the FULL AcademicLevel band
        // (1-12 here) before granting eligibility — a different rule
        // from the normalized-grade candidate-matching under test, so
        // the eligibility row is created directly rather than via
        // assign()'s own (already-covered-elsewhere) validation.
        InstructorCurriculumEligibility::query()->create([
            'teacher_id' => $instructor->id,
            'education_system_id' => $fixture['system']->id,
            'curriculum_id' => $fixture['curriculum']->id,
            'is_active' => true,
            'approved_at' => now(),
        ]);
        $student = $this->makeStudent($fixture['country']);

        $this->actingAs($student);

        $booking = app(WizardBookingServiceInterface::class)->book($this->wizardData([...$fixture, 'instructor' => $instructor], 3, teacherId: $instructor->id));
        $this->assertSame(10, $booking->academicContext->normalized_grade);
    }

    public function test_instructor_range_not_covering_the_levels_normalized_grade_is_rejected(): void
    {
        $this->enableGlobally();
        $fixture = $this->buildFixture('UncoveredGrade', 10);
        $instructor = $this->makeInstructor($fixture['subject'], 6, 9); // does not cover Class 10
        InstructorCurriculumEligibility::query()->create([
            'teacher_id' => $instructor->id,
            'education_system_id' => $fixture['system']->id,
            'curriculum_id' => $fixture['curriculum']->id,
            'is_active' => true,
            'approved_at' => now(),
        ]);
        $student = $this->makeStudent($fixture['country']);

        $this->actingAs($student);

        $this->expectException(BookingException::class);
        app(WizardBookingServiceInterface::class)->book($this->wizardData([...$fixture, 'instructor' => $instructor], 3, teacherId: $instructor->id));
    }

    public function test_level_with_no_normalized_grade_is_unsupported_for_demo_booking(): void
    {
        $this->enableGlobally();
        $fixture = $this->buildFixture('NonNumeric', null);
        $instructor = $this->makeInstructor($fixture['subject']);
        $this->eligibilityService()->assign($this->admin, $instructor, $fixture['system'], $fixture['curriculum']);
        $student = $this->makeStudent($fixture['country']);

        $this->actingAs($student);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('This level is not currently supported for demo booking. Please select a different level.');
        app(WizardBookingServiceInterface::class)->book($this->wizardData([...$fixture, 'instructor' => $instructor], 3, teacherId: $instructor->id));
    }

    public function test_no_configured_levels_never_synthesizes_a_1_to_12_fallback(): void
    {
        $this->enableGlobally();
        $country = Country::factory()->create();
        $system = $this->educationSystemService()->createEducationSystem($this->admin, ['name' => 'Empty Levels System', 'slug' => 'empty-levels-system']);
        $this->educationSystemService()->mapToCountry($this->admin, $system, $country);

        $levels = app(DemoAcademicContextResolver::class)->levelsFor($country, $system);

        $this->assertCount(0, $levels);
    }
}
