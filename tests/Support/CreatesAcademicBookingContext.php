<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Curriculum\Services\CurriculumService;
use App\Curriculum\Services\EducationSystemService;
use App\Curriculum\Services\InstructorAcademicEligibilityService;
use App\Models\AcademicCategory;
use App\Models\AcademicLevel;
use App\Models\Country;
use App\Models\Curriculum;
use App\Models\EducationSystem;
use App\Models\EducationSystemLevel;
use App\Models\Subject;
use App\Models\SubjectTopic;
use App\Models\User;
use App\Models\UserProfile;
use App\Settings\FeatureSettings;
use Carbon\CarbonImmutable;
use Database\Seeders\AcademicPermissionSeeder;
use Livewire\Features\SupportTesting\Testable;
use Spatie\Permission\Models\Role;

/**
 * Canonical academic booking context for tests.
 *
 * Country-aware academics are mandatory: there is no legacy
 * subject/grade booking path any more, so EVERY booking test needs a
 * complete Country -> EducationSystem -> Level -> Subject -> Curriculum
 * chain plus an eligible instructor. That is ~40 lines of fixture, and
 * duplicating it per test file is how fixtures drift apart.
 *
 * This trait is test infrastructure only — nothing here exists to make
 * production code testable, and no production API was added or kept
 * alive for it.
 */
trait CreatesAcademicBookingContext
{
    private ?User $academicAdminUser = null;

    /**
     * Roles + academic permissions the services below authorize against.
     * Safe to call more than once.
     */
    protected function bootAcademicBookingContext(): void
    {
        $this->seed(AcademicPermissionSeeder::class);

        foreach (['manager', 'instructor', 'student'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    /** The privileged actor the curriculum/education-system services require. */
    protected function academicAdmin(): User
    {
        if ($this->academicAdminUser === null) {
            $this->academicAdminUser = User::factory()->create(['status' => User::STATUS_ACTIVE]);
            $this->academicAdminUser->assignRole('manager');
        }

        return $this->academicAdminUser;
    }

    /**
     * A complete, published academic context a student can actually book
     * against.
     *
     * @return array{country: Country, system: EducationSystem, academicLevel: AcademicLevel, level: EducationSystemLevel, subject: Subject, curriculum: Curriculum}
     */
    protected function seedAcademicContext(
        string $prefix = 'ACAD',
        ?Country $country = null,
        int $normalizedGrade = 10,
        string $levelTerm = 'Class',
    ): array {
        $admin = $this->academicAdmin();
        $systems = app(EducationSystemService::class);

        $country ??= Country::factory()->create();
        $slug = strtolower($prefix);

        $system = $systems->createEducationSystem($admin, [
            'name' => "{$prefix} System",
            'slug' => $slug.'-system',
            'level_term_singular' => $levelTerm,
            'level_term_plural' => $levelTerm.'es',
        ]);

        $academicLevel = AcademicLevel::create([
            'name' => ucfirst($slug).' Band',
            'slug' => $slug.'-band',
            'min_grade' => 1,
            'max_grade' => 12,
        ]);

        $category = AcademicCategory::query()->firstOrCreate(
            ['slug' => 'academic-context-general'],
            ['name' => 'Academic Context General'],
        );
        $subject = Subject::create([
            'academic_category_id' => $category->id,
            'name' => ucfirst($slug).' Subject',
            'slug' => $slug.'-subject',
            'status' => 'active',
        ]);

        $curriculum = $this->publishCurriculum($subject, $academicLevel, "{$prefix} Curriculum");

        $systems->mapToCountry($admin, $system, $country);
        $systems->mapToAcademicLevel($admin, $system, $academicLevel);
        $systems->mapToCurriculum($admin, $system, $curriculum);

        $level = $systems->addLevel($admin, $system, [
            'academic_level_id' => $academicLevel->id,
            'value' => (string) $normalizedGrade,
            'display_label' => "{$levelTerm} {$normalizedGrade}",
            'normalized_grade' => $normalizedGrade,
        ]);

        return compact('country', 'system', 'academicLevel', 'level', 'subject', 'curriculum');
    }

    /** A published curriculum version — an unpublished one is not bookable. */
    protected function publishCurriculum(Subject $subject, AcademicLevel $level, string $name): Curriculum
    {
        $admin = $this->academicAdmin();
        $curricula = app(CurriculumService::class);

        $curriculum = $curricula->createCurriculum($admin, [
            'subject_id' => $subject->id,
            'academic_level_id' => $level->id,
            'name' => $name,
        ]);

        $version = $curriculum->latestVersion();
        $module = $curricula->addModule($admin, $version, ['title' => 'Module 1']);
        $curricula->assignTopic($admin, $module, SubjectTopic::factory()->create(['subject_id' => $subject->id]));
        $curricula->publish($admin, $version);

        return $curriculum->refresh();
    }

    /**
     * Marks an existing instructor eligible to teach this system's
     * curriculum — without it the wizard offers no instructor and the
     * slot list is empty.
     */
    protected function makeInstructorEligible(User $instructor, EducationSystem $system, Curriculum $curriculum): void
    {
        app(InstructorAcademicEligibilityService::class)->assign($this->academicAdmin(), $instructor, $system, $curriculum);
    }

    /** Points a student's profile at the country the academic context is mapped to. */
    protected function assignAcademicCountry(User $student, Country $country): void
    {
        UserProfile::updateOrCreate(['user_id' => $student->id], ['country_id' => $country->id]);
    }

    protected function enableDemoLessons(): void
    {
        $settings = app(FeatureSettings::class);
        $settings->demo_lessons_enabled = true;
        $settings->save();
    }

    /**
     * Walks the canonical wizard from session type to a chosen slot.
     *
     * This is the ONLY supported navigation: mode -> education system ->
     * level -> academic subject -> curriculum -> (billing mode for paid)
     * -> date -> time. The removed legacy selectSubject()/selectGrade()
     * path must never reappear here.
     *
     * @param  array<string, mixed>  $context  the result of seedAcademicContext()
     */
    protected function navigateAcademicWizardToSlot(
        Testable $component,
        array $context,
        CarbonImmutable $slot,
        string $mode = 'paid_one_to_one',
        ?string $billingMode = 'single',
    ): Testable {
        $component
            ->call('selectMode', $mode)
            ->call('selectEducationSystem', $context['system']->id)
            ->call('selectLevel', $context['level']->id)
            ->call('selectAcademicSubject', $context['subject']->id)
            ->call('selectCurriculum', $context['curriculum']->id);

        if ($billingMode !== null) {
            $component->call('selectBillingMode', $billingMode);
        }

        // The calendar opens on the current month; step forward until the
        // target month is visible or the date is not selectable.
        for (
            $month = CarbonImmutable::now('UTC')->startOfMonth();
            $month->lt($slot->startOfMonth());
            $month = $month->addMonthNoOverflow()
        ) {
            $component->call('nextMonth');
        }

        return $component
            ->call('selectDate', $slot->toDateString())
            ->call('selectSlot', $slot->toIso8601String());
    }
}
