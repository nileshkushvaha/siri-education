<?php

declare(strict_types=1);

namespace Tests\Feature\Package;

use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Enums\Weekday;
use App\Curriculum\Services\CurriculumService;
use App\Curriculum\Services\EducationSystemService;
use App\Exceptions\HistoricalRecordCannotBeDeletedException;
use App\Exceptions\ImmutableRecordCannotBeUpdatedException;
use App\Models\AcademicCategory;
use App\Models\AcademicLevel;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Country;
use App\Models\Curriculum;
use App\Models\InstructorCurriculumEligibility;
use App\Models\PackageAcademicContext;
use App\Models\PackageBenefitRule;
use App\Models\StudentLessonPrice;
use App\Models\Subject;
use App\Models\SubjectTopic;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use App\Models\UserProfile;
use App\Package\DTOs\CreatePackageProposalData;
use App\Package\Exceptions\PackageException;
use App\Package\Services\InstructorPackageProposalService;
use App\Settings\FeatureSettings;
use Database\Seeders\AcademicPermissionSeeder;
use Database\Seeders\PackagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 4D — the package's structured academic identity (spec Part 42)
 * and its historical immutability (Part 47).
 *
 * The guarantees pinned here are the ones that make package↔booking
 * matching deterministic rather than fuzzy:
 *
 *  - the instructor's posted ids are never trusted — country is
 *    server-resolved, and a forged system/level is rejected;
 *  - eligibility is enforced at PROPOSAL time, not deferred to booking;
 *  - the snapshot is frozen at submission and a later republish of the
 *    curriculum never rewrites it, while a NEW proposal does pick up
 *    the newer version.
 */
class PackageAcademicContextTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AcademicPermissionSeeder::class);
        $this->seed(PackagePermissionSeeder::class);

        foreach (['manager', 'instructor', 'student'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole('manager');

        BookingType::factory()->create(['key' => 'paid_one_to_one', 'duration_minutes' => 60, 'is_paid' => true]);
    }

    // ── Fixture ───────────────────────────────────────────────────────────

    private function proposals(): InstructorPackageProposalService
    {
        return app(InstructorPackageProposalService::class);
    }

    private function enablePackages(): void
    {
        $settings = app(FeatureSettings::class);
        $settings->country_academic_packages_enabled = true;
        $settings->save();
    }

    private function publishedCurriculum(Subject $subject, AcademicLevel $level, string $name): Curriculum
    {
        $curriculum = app(CurriculumService::class)->createCurriculum($this->admin, [
            'subject_id' => $subject->id,
            'academic_level_id' => $level->id,
            'name' => $name,
        ]);

        $version = $curriculum->latestVersion();
        $module = app(CurriculumService::class)->addModule($this->admin, $version, ['title' => 'Module 1']);
        $topic = SubjectTopic::factory()->create(['subject_id' => $subject->id]);
        app(CurriculumService::class)->assignTopic($this->admin, $module, $topic);
        app(CurriculumService::class)->publish($this->admin, $version);

        return $curriculum->refresh();
    }

    /** @return array<string, mixed> */
    private function fixture(string $prefix, int $normalizedGrade = 10): array
    {
        $systems = app(EducationSystemService::class);

        $country = Country::factory()->create(['status' => 'active']);
        $system = $systems->createEducationSystem($this->admin, [
            'name' => "{$prefix} Board",
            'slug' => strtolower($prefix).'-4d-board',
            'level_term_singular' => 'Class',
            'level_term_plural' => 'Classes',
        ]);

        $academicLevel = AcademicLevel::create([
            'name' => "{$prefix} Band",
            'slug' => strtolower($prefix).'-4d-band',
            'min_grade' => 1,
            'max_grade' => 12,
        ]);

        $category = AcademicCategory::query()->firstOrCreate(['slug' => '4d-general'], ['name' => '4D General']);
        $subject = Subject::create([
            'academic_category_id' => $category->id,
            'name' => "{$prefix} Maths",
            'slug' => strtolower($prefix).'-4d-maths',
        ]);

        $curriculum = $this->publishedCurriculum($subject, $academicLevel, "{$prefix} Curriculum");

        $systems->mapToCountry($this->admin, $system, $country);
        $systems->mapToAcademicLevel($this->admin, $system, $academicLevel);
        $systems->mapToCurriculum($this->admin, $system, $curriculum);

        $level = $systems->addLevel($this->admin, $system, [
            'academic_level_id' => $academicLevel->id,
            'value' => (string) $normalizedGrade,
            'display_label' => "Class {$normalizedGrade}",
            'normalized_grade' => $normalizedGrade,
        ]);

        return compact('country', 'system', 'academicLevel', 'level', 'subject', 'curriculum');
    }

    private function instructorFor(array $f, bool $eligible = true): User
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');
        UserProfile::updateOrCreate(['user_id' => $instructor->id], ['instructor_status' => 'approved']);

        TeacherSubject::factory()->create([
            'teacher_id' => $instructor->id,
            'subject' => $f['subject']->name,
            'subject_id' => $f['subject']->id,
            'grade_from' => 1,
            'grade_to' => 12,
        ]);

        foreach (Weekday::cases() as $day) {
            TeacherAvailability::factory()->state(['teacher_id' => $instructor->id])->forDay($day)->between('09:00:00', '17:00:00')->create();
        }

        if ($eligible) {
            InstructorCurriculumEligibility::query()->create([
                'teacher_id' => $instructor->id,
                'education_system_id' => $f['system']->id,
                'curriculum_id' => $f['curriculum']->id,
                'is_active' => true,
                'approved_at' => now(),
            ]);
        }

        return $instructor;
    }

    private function studentFor(array $f, User $instructor): User
    {
        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        UserProfile::updateOrCreate(['user_id' => $student->id], ['country_id' => $f['country']->id]);

        // The existing paid relationship a package proposal requires.
        Booking::factory()->create([
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'status' => BookingStatus::Completed,
            'payment_status' => BookingPaymentStatus::Paid,
        ]);

        StudentLessonPrice::factory()->create([
            'booking_type_id' => BookingType::query()->where('key', 'paid_one_to_one')->value('id'),
            'subject_id' => $f['subject']->id,
            'academic_level_id' => $f['academicLevel']->id,
            'country_id' => $f['country']->id,
            'duration_minutes' => 60,
            'amount_minor' => 2500,
        ]);

        return $student;
    }

    private function rule(): PackageBenefitRule
    {
        return PackageBenefitRule::query()->create([
            'name' => '10 + 2 Package',
            'paid_quantity' => 10,
            'bonus_quantity' => 2,
            'total_quantity' => 12,
            'validity_days' => 90,
            'is_active' => true,
        ]);
    }

    private function proposalData(array $f, User $instructor, User $student, array $overrides = []): CreatePackageProposalData
    {
        return new CreatePackageProposalData(
            instructorId: (int) $instructor->id,
            studentId: (int) $student->id,
            packageBenefitRuleId: $overrides['rule'] ?? $this->rule()->id,
            subjectId: $overrides['subject'] ?? $f['subject']->id,
            academicLevelId: null,
            educationSystemId: $overrides['system'] ?? $f['system']->id,
            educationSystemLevelId: $overrides['level'] ?? $f['level']->id,
        );
    }

    // ── 1-10. Structured creation ─────────────────────────────────────────

    public function test_submitting_freezes_the_full_structured_academic_context(): void
    {
        $this->enablePackages();
        $f = $this->fixture('Freeze');
        $instructor = $this->instructorFor($f);
        $student = $this->studentFor($f, $instructor);

        $proposal = $this->proposals()->proposeAndSubmit($this->proposalData($f, $instructor, $student));
        $context = $proposal->academicContext;

        $this->assertNotNull($context);
        $this->assertSame($f['country']->id, (int) $context->country_id);
        $this->assertSame($f['system']->id, $context->education_system_id);
        $this->assertSame($f['level']->id, $context->education_system_level_id);
        $this->assertSame($f['subject']->id, $context->subject_id);
        $this->assertSame($f['curriculum']->id, $context->curriculum_id);
        $this->assertSame($f['curriculum']->latestPublishedVersion()->id, $context->curriculum_version_id);
    }

    public function test_the_snapshot_denormalizes_display_values_including_dynamic_level_terminology(): void
    {
        $this->enablePackages();
        $f = $this->fixture('Terms');
        $instructor = $this->instructorFor($f);
        $student = $this->studentFor($f, $instructor);

        $context = $this->proposals()->proposeAndSubmit($this->proposalData($f, $instructor, $student))->academicContext;

        // "Class", not a hardcoded "Grade" — the system's own terminology.
        $this->assertSame('Class', $context->level_term);
        $this->assertSame('Class 10', $context->level_display);
        $this->assertSame('10', $context->level_value);
        $this->assertSame(10, $context->normalized_grade);
        $this->assertSame($f['country']->name, $context->country_name);
        $this->assertSame($f['system']->name, $context->education_system_name);
    }

    public function test_the_academic_level_is_derived_from_the_selected_level_not_the_posted_one(): void
    {
        $this->enablePackages();
        $f = $this->fixture('Derive');
        $other = $this->fixture('DeriveOther');
        $instructor = $this->instructorFor($f);
        $student = $this->studentFor($f, $instructor);

        // A forged, unrelated AcademicLevel is supplied alongside a valid
        // structured selection. The derived value must win.
        $proposal = $this->proposals()->proposeAndSubmit(new CreatePackageProposalData(
            instructorId: (int) $instructor->id,
            studentId: (int) $student->id,
            packageBenefitRuleId: $this->rule()->id,
            subjectId: $f['subject']->id,
            academicLevelId: $other['academicLevel']->id,
            educationSystemId: $f['system']->id,
            educationSystemLevelId: $f['level']->id,
        ));

        $this->assertSame($f['academicLevel']->id, $proposal->academicContext->academic_level_id);
        // The legacy compatibility column agrees with the snapshot.
        $this->assertSame($f['academicLevel']->id, $proposal->academic_level_id);
    }

    public function test_a_forged_education_system_id_is_rejected(): void
    {
        $this->enablePackages();
        $f = $this->fixture('ForgeSys');
        $other = $this->fixture('ForgeSysOther');
        $instructor = $this->instructorFor($f);
        $student = $this->studentFor($f, $instructor);

        $this->expectException(PackageException::class);

        // A system that is real, but not available in this student's country.
        $this->proposals()->proposeAndSubmit(
            $this->proposalData($f, $instructor, $student, ['system' => $other['system']->id]),
        );
    }

    public function test_a_forged_education_system_level_id_is_rejected(): void
    {
        $this->enablePackages();
        $f = $this->fixture('ForgeLvl');
        $other = $this->fixture('ForgeLvlOther');
        $instructor = $this->instructorFor($f);
        $student = $this->studentFor($f, $instructor);

        $this->expectException(PackageException::class);

        // A level belonging to a different education system.
        $this->proposals()->proposeAndSubmit(
            $this->proposalData($f, $instructor, $student, ['level' => $other['level']->id]),
        );
    }

    public function test_an_instructor_ineligible_for_the_curriculum_cannot_create_the_package(): void
    {
        $this->enablePackages();
        $f = $this->fixture('Ineligible');
        $instructor = $this->instructorFor($f, eligible: false);
        $student = $this->studentFor($f, $instructor);

        $this->expectException(PackageException::class);
        $this->expectExceptionMessage('not academically eligible');

        // Enforced at PROPOSAL time — never deferred to booking.
        $this->proposals()->proposeAndSubmit($this->proposalData($f, $instructor, $student));
    }

    public function test_an_unrelated_student_is_still_rejected_in_the_structured_flow(): void
    {
        $this->enablePackages();
        $f = $this->fixture('Unrelated');
        $instructor = $this->instructorFor($f);

        $stranger = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);
        $stranger->assignRole('student');
        UserProfile::updateOrCreate(['user_id' => $stranger->id], ['country_id' => $f['country']->id]);

        $this->expectException(PackageException::class);
        $this->expectExceptionMessage('no existing paid relationship');

        $this->proposals()->proposeAndSubmit($this->proposalData($f, $instructor, $stranger));
    }

    public function test_the_country_comes_from_the_student_profile_not_the_instructor(): void
    {
        $this->enablePackages();
        $f = $this->fixture('ServerCountry');
        $instructor = $this->instructorFor($f);
        $student = $this->studentFor($f, $instructor);

        // The instructor sits in a completely different country; the
        // package must still resolve the STUDENT's country.
        $instructorCountry = Country::factory()->create(['status' => 'active']);
        UserProfile::updateOrCreate(['user_id' => $instructor->id], ['country_id' => $instructorCountry->id]);

        $context = $this->proposals()->proposeAndSubmit($this->proposalData($f, $instructor, $student))->academicContext;

        $this->assertSame($f['country']->id, (int) $context->country_id);
        $this->assertNotSame($instructorCountry->id, (int) $context->country_id);
    }

    // ── 15-17, 61-64. Historical immutability ─────────────────────────────

    public function test_the_frozen_snapshot_cannot_be_updated(): void
    {
        $this->enablePackages();
        $f = $this->fixture('Immutable');
        $instructor = $this->instructorFor($f);
        $student = $this->studentFor($f, $instructor);

        $context = $this->proposals()->proposeAndSubmit($this->proposalData($f, $instructor, $student))->academicContext;

        $this->expectException(ImmutableRecordCannotBeUpdatedException::class);

        $context->update(['subject_name' => 'Rewritten']);
    }

    public function test_the_frozen_snapshot_cannot_be_deleted(): void
    {
        $this->enablePackages();
        $f = $this->fixture('NoDelete');
        $instructor = $this->instructorFor($f);
        $student = $this->studentFor($f, $instructor);

        $context = $this->proposals()->proposeAndSubmit($this->proposalData($f, $instructor, $student))->academicContext;

        $this->expectException(HistoricalRecordCannotBeDeletedException::class);

        $context->delete();
    }

    public function test_renaming_master_data_later_does_not_rewrite_the_package_snapshot(): void
    {
        $this->enablePackages();
        $f = $this->fixture('Rename');
        $instructor = $this->instructorFor($f);
        $student = $this->studentFor($f, $instructor);

        $context = $this->proposals()->proposeAndSubmit($this->proposalData($f, $instructor, $student))->academicContext;
        $originalSystemName = $context->education_system_name;
        $originalSubjectName = $context->subject_name;

        $f['system']->forceFill(['name' => 'Completely Renamed Board'])->save();
        $f['subject']->forceFill(['name' => 'Completely Renamed Subject'])->save();

        $context->refresh();

        $this->assertSame($originalSystemName, $context->education_system_name);
        $this->assertSame($originalSubjectName, $context->subject_name);
    }

    public function test_publishing_a_new_curriculum_version_does_not_upgrade_an_existing_package(): void
    {
        $this->enablePackages();
        $f = $this->fixture('Versioned');
        $instructor = $this->instructorFor($f);
        $student = $this->studentFor($f, $instructor);

        $proposal = $this->proposals()->proposeAndSubmit($this->proposalData($f, $instructor, $student));
        $v1 = $proposal->academicContext->curriculum_version_id;
        $v1Number = $proposal->academicContext->curriculum_version_number;

        $this->publishNextVersion($f);

        $proposal->refresh();

        // Still pinned to the version it was sold under.
        $this->assertSame($v1, $proposal->academicContext->curriculum_version_id);
        $this->assertSame($v1Number, $proposal->academicContext->curriculum_version_number);
        $this->assertNotSame($v1, $f['curriculum']->refresh()->latestPublishedVersion()->id);
    }

    public function test_a_new_proposal_receives_the_newly_published_version(): void
    {
        $this->enablePackages();
        $f = $this->fixture('NewVersion');
        $instructor = $this->instructorFor($f);
        $student = $this->studentFor($f, $instructor);

        $first = $this->proposals()->proposeAndSubmit($this->proposalData($f, $instructor, $student));
        $this->publishNextVersion($f);
        $second = $this->proposals()->proposeAndSubmit($this->proposalData($f, $instructor, $student));

        $this->assertNotSame(
            $first->academicContext->curriculum_version_id,
            $second->academicContext->curriculum_version_id,
        );
        $this->assertSame(
            $f['curriculum']->refresh()->latestPublishedVersion()->id,
            $second->academicContext->curriculum_version_id,
        );
    }

    public function test_editing_the_package_offer_later_does_not_alter_a_submitted_proposal(): void
    {
        $this->enablePackages();
        $f = $this->fixture('RuleEdit');
        $instructor = $this->instructorFor($f);
        $student = $this->studentFor($f, $instructor);
        $rule = $this->rule();

        $proposal = $this->proposals()->proposeAndSubmit(
            $this->proposalData($f, $instructor, $student, ['rule' => $rule->id]),
        );

        $rule->forceFill(['paid_quantity' => 50, 'bonus_quantity' => 10, 'total_quantity' => 60, 'validity_days' => 400])->save();

        $proposal->refresh();

        $this->assertSame(10, $proposal->paid_quantity);
        $this->assertSame(2, $proposal->bonus_quantity);
        $this->assertSame(12, $proposal->total_quantity);
        $this->assertSame(90, $proposal->validity_days);
    }

    // ── Legacy / feature gating ───────────────────────────────────────────

    public function test_with_the_feature_off_no_structured_context_is_frozen(): void
    {
        // Feature deliberately NOT enabled — the legacy Subject +
        // optional AcademicLevel shape must keep working.
        $f = $this->fixture('LegacyOff');
        $instructor = $this->instructorFor($f);
        $student = $this->studentFor($f, $instructor);

        $proposal = $this->proposals()->proposeAndSubmit(new CreatePackageProposalData(
            instructorId: (int) $instructor->id,
            studentId: (int) $student->id,
            packageBenefitRuleId: $this->rule()->id,
            subjectId: $f['subject']->id,
            academicLevelId: $f['academicLevel']->id,
        ));

        $this->assertNull($proposal->academicContext);
        $this->assertSame(0, PackageAcademicContext::query()->count());
    }

    public function test_with_the_feature_on_an_incomplete_selection_is_refused_rather_than_downgraded(): void
    {
        $this->enablePackages();
        $f = $this->fixture('NoFallback');
        $instructor = $this->instructorFor($f);
        $student = $this->studentFor($f, $instructor);

        $this->expectException(PackageException::class);

        // Fail closed — never a silent fallback to the legacy shape.
        $this->proposals()->proposeAndSubmit(new CreatePackageProposalData(
            instructorId: (int) $instructor->id,
            studentId: (int) $student->id,
            packageBenefitRuleId: $this->rule()->id,
            subjectId: $f['subject']->id,
            academicLevelId: null,
            educationSystemId: null,
            educationSystemLevelId: null,
        ));
    }

    public function test_one_snapshot_per_proposal(): void
    {
        $this->enablePackages();
        $f = $this->fixture('OneOnly');
        $instructor = $this->instructorFor($f);
        $student = $this->studentFor($f, $instructor);

        $proposal = $this->proposals()->proposeAndSubmit($this->proposalData($f, $instructor, $student));

        $this->assertSame(
            1,
            PackageAcademicContext::query()->where('proposal_id', $proposal->id)->count(),
        );
    }

    /** Publishes a second, higher version of the fixture's curriculum. */
    private function publishNextVersion(array $f): void
    {
        $curricula = app(CurriculumService::class);
        $version = $curricula->createNewVersion($this->admin, $f['curriculum']->refresh());
        $module = $curricula->addModule($this->admin, $version, ['title' => 'Module 2']);
        $topic = SubjectTopic::factory()->create(['subject_id' => $f['subject']->id]);
        $curricula->assignTopic($this->admin, $module, $topic);
        $curricula->publish($this->admin, $version);
    }
}
