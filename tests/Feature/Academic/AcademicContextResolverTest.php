<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Curriculum\Enums\CurriculumVersionStatus;
use App\Curriculum\Exceptions\AcademicContextException;
use App\Curriculum\Services\AcademicContextResolver;
use App\Curriculum\Services\CurriculumService;
use App\Curriculum\Services\EducationSystemService;
use App\Enums\AcademicStatus;
use App\Models\AcademicCategory;
use App\Models\AcademicLevel;
use App\Models\Country;
use App\Models\Curriculum;
use App\Models\EducationSystem;
use App\Models\Subject;
use App\Models\SubjectTopic;
use App\Models\User;
use Database\Seeders\AcademicPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AcademicContextResolverTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AcademicPermissionSeeder::class);

        $role = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $this->manager = User::factory()->create(['status' => 'active']);
        $this->manager->assignRole($role);
    }

    private function resolver(): AcademicContextResolver
    {
        return app(AcademicContextResolver::class);
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
        $category = AcademicCategory::query()->firstOrCreate(['slug' => 'school'], ['name' => 'School']);

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
        ]);
    }

    private function topic(Subject $subject, string $slug): SubjectTopic
    {
        return SubjectTopic::factory()->create([
            'subject_id' => $subject->id,
            'name' => ucfirst($slug),
            'slug' => $slug,
            'status' => AcademicStatus::Active,
        ]);
    }

    /** Builds and publishes a curriculum with one module + one topic, so latestPublishedVersion() resolves. */
    private function publishedCurriculum(Subject $subject, AcademicLevel $level, string $name): Curriculum
    {
        $curriculum = $this->curriculumService()->createCurriculum($this->manager, [
            'subject_id' => $subject->id,
            'academic_level_id' => $level->id,
            'name' => $name,
        ]);

        $version = $curriculum->latestVersion();
        $module = $this->curriculumService()->addModule($this->manager, $version, ['title' => 'Module 1']);
        $topic = $this->topic($subject, strtolower(str_replace(' ', '-', $name)).'-topic');
        $this->curriculumService()->assignTopic($this->manager, $module, $topic);
        $this->curriculumService()->publish($this->manager, $version);

        return $curriculum->refresh();
    }

    /**
     * @return array{country: Country, system: EducationSystem, level: AcademicLevel, subject: Subject, curriculum: Curriculum}
     */
    private function buildFixture(string $prefix): array
    {
        $country = Country::factory()->create();
        $system = $this->educationSystemService()->createEducationSystem($this->manager, ['name' => "{$prefix} System", 'slug' => strtolower($prefix).'-system']);
        $level = $this->level(strtolower($prefix).'-level');
        $subject = $this->subject(strtolower($prefix).'-subject');
        $curriculum = $this->publishedCurriculum($subject, $level, "{$prefix} Curriculum");

        $this->educationSystemService()->mapToCountry($this->manager, $system, $country);
        $this->educationSystemService()->mapToAcademicLevel($this->manager, $system, $level);
        $this->educationSystemService()->mapToCurriculum($this->manager, $system, $curriculum);

        return compact('country', 'system', 'level', 'subject', 'curriculum');
    }

    // ── India-style fixture ──────────────────────────────────────────────

    public function test_india_style_fixture_resolves_only_its_own_chain(): void
    {
        $india = $this->buildFixture('India');

        $systems = $this->resolver()->educationSystemsForCountry($india['country']);
        $this->assertTrue($systems->contains('id', $india['system']->id));

        $levels = $this->resolver()->academicLevelsForSystem($india['country'], $india['system']);
        $this->assertTrue($levels->contains('id', $india['level']->id));

        $subjects = $this->resolver()->subjectsForContext($india['country'], $india['system'], $india['level']);
        $this->assertTrue($subjects->contains('id', $india['subject']->id));

        $curricula = $this->resolver()->curriculaForContext($india['country'], $india['system'], $india['level'], $india['subject']);
        $this->assertTrue($curricula->contains('id', $india['curriculum']->id));

        $context = $this->resolver()->resolveContext($india['country'], $india['system'], $india['level'], $india['subject'], $india['curriculum']);
        $this->assertSame($india['curriculum']->id, $context->curriculumId);
        $this->assertNotNull($context->curriculumVersionId);
    }

    // ── US-style fixture: cross-country isolation ────────────────────────

    public function test_us_style_fixture_is_isolated_from_india_fixture(): void
    {
        $india = $this->buildFixture('India');
        $us = $this->buildFixture('US');

        $indiaSystems = $this->resolver()->educationSystemsForCountry($india['country']);
        $this->assertFalse($indiaSystems->contains('id', $us['system']->id));

        $usSystems = $this->resolver()->educationSystemsForCountry($us['country']);
        $this->assertFalse($usSystems->contains('id', $india['system']->id));

        $this->assertFalse($this->resolver()->isValidContext($india['country'], $us['system'], $us['level'], $us['subject'], $us['curriculum']));
    }

    // ── International/shared system fixture ──────────────────────────────

    public function test_shared_education_system_resolves_in_multiple_countries(): void
    {
        $countryA = Country::factory()->create();
        $countryB = Country::factory()->create();
        $system = $this->educationSystemService()->createEducationSystem($this->manager, ['name' => 'IB', 'slug' => 'ib-shared']);
        $level = $this->level('ib-level');
        $subject = $this->subject('ib-subject');
        $curriculum = $this->publishedCurriculum($subject, $level, 'IB Curriculum');

        $this->educationSystemService()->mapToCountry($this->manager, $system, $countryA);
        $this->educationSystemService()->mapToCountry($this->manager, $system, $countryB);
        $this->educationSystemService()->mapToAcademicLevel($this->manager, $system, $level);
        $this->educationSystemService()->mapToCurriculum($this->manager, $system, $curriculum);

        $contextA = $this->resolver()->resolveContext($countryA, $system, $level, $subject, $curriculum);
        $contextB = $this->resolver()->resolveContext($countryB, $system, $level, $subject, $curriculum);

        $this->assertSame($curriculum->id, $contextA->curriculumId);
        $this->assertSame($curriculum->id, $contextB->curriculumId);
    }

    // ── Invalid combinations ──────────────────────────────────────────────

    public function test_wrong_country_system_combination_is_rejected(): void
    {
        $india = $this->buildFixture('IndiaX');
        $otherCountry = Country::factory()->create();

        $this->expectException(AcademicContextException::class);
        $this->resolver()->resolveContext($otherCountry, $india['system'], $india['level'], $india['subject'], $india['curriculum']);
    }

    public function test_wrong_system_level_combination_is_rejected(): void
    {
        $india = $this->buildFixture('IndiaY');
        $unmappedLevel = $this->level('unmapped-level');

        $this->expectException(AcademicContextException::class);
        $this->resolver()->resolveContext($india['country'], $india['system'], $unmappedLevel, $india['subject'], $india['curriculum']);
    }

    public function test_country_unavailable_subject_is_rejected(): void
    {
        $india = $this->buildFixture('IndiaZ');
        $otherCountry = Country::factory()->create();
        $otherSystem = $this->educationSystemService()->createEducationSystem($this->manager, ['name' => 'Other', 'slug' => 'other-sys']);
        $this->educationSystemService()->mapToCountry($this->manager, $otherSystem, $otherCountry);
        $this->educationSystemService()->mapToAcademicLevel($this->manager, $otherSystem, $india['level']);

        // Restrict the subject explicitly to a country that is not $otherCountry.
        $india['subject']->countries()->attach($india['country']->id);

        $this->expectException(AcademicContextException::class);
        $this->resolver()->resolveContext($otherCountry, $otherSystem, $india['level'], $india['subject'], $india['curriculum']);
    }

    public function test_curriculum_with_wrong_subject_is_rejected(): void
    {
        $india = $this->buildFixture('IndiaW');
        $otherSubject = $this->subject('other-subject-w');

        $this->expectException(AcademicContextException::class);
        $this->resolver()->resolveContext($india['country'], $india['system'], $india['level'], $otherSubject, $india['curriculum']);
    }

    public function test_curriculum_with_wrong_level_is_rejected(): void
    {
        $india = $this->buildFixture('IndiaV');
        $otherLevel = $this->level('other-level-v');
        $this->educationSystemService()->mapToAcademicLevel($this->manager, $india['system'], $otherLevel);

        $this->expectException(AcademicContextException::class);
        $this->resolver()->resolveContext($india['country'], $india['system'], $otherLevel, $india['subject'], $india['curriculum']);
    }

    public function test_curriculum_not_mapped_to_selected_system_is_rejected(): void
    {
        $india = $this->buildFixture('IndiaU');
        $otherSystem = $this->educationSystemService()->createEducationSystem($this->manager, ['name' => 'Other U', 'slug' => 'other-u']);
        $this->educationSystemService()->mapToCountry($this->manager, $otherSystem, $india['country']);
        $this->educationSystemService()->mapToAcademicLevel($this->manager, $otherSystem, $india['level']);

        // $india['curriculum'] is mapped to $india['system'] only, not $otherSystem.
        $this->expectException(AcademicContextException::class);
        $this->resolver()->resolveContext($india['country'], $otherSystem, $india['level'], $india['subject'], $india['curriculum']);
    }

    public function test_curriculum_with_no_published_version_is_rejected(): void
    {
        $country = Country::factory()->create();
        $system = $this->educationSystemService()->createEducationSystem($this->manager, ['name' => 'Draft Sys', 'slug' => 'draft-sys']);
        $level = $this->level('draft-level');
        $subject = $this->subject('draft-subject');

        $curriculum = $this->curriculumService()->createCurriculum($this->manager, [
            'subject_id' => $subject->id,
            'academic_level_id' => $level->id,
            'name' => 'Unpublished Curriculum',
        ]);

        $this->educationSystemService()->mapToCountry($this->manager, $system, $country);
        $this->educationSystemService()->mapToAcademicLevel($this->manager, $system, $level);
        $this->educationSystemService()->mapToCurriculum($this->manager, $system, $curriculum);

        $this->expectException(AcademicContextException::class);
        $this->resolver()->resolveContext($country, $system, $level, $subject, $curriculum);
    }

    // ── Phase 1.1: Subject visibility vs. Curriculum selectability ────────

    /**
     * Subject exists, Curriculum is Draft only: the Subject must still
     * appear in subjectsForContext() (regional academic visibility does
     * not depend on curriculum lifecycle), but curriculaForContext()
     * must not expose the Draft as selectable, and resolveContext()
     * must not resolve it.
     */
    public function test_subject_visible_with_draft_only_curriculum_but_curriculum_not_selectable(): void
    {
        $country = Country::factory()->create();
        $system = $this->educationSystemService()->createEducationSystem($this->manager, ['name' => 'Draft Only Sys', 'slug' => 'draft-only-sys']);
        $level = $this->level('draft-only-level');
        $subject = $this->subject('draft-only-subject');

        $curriculum = $this->curriculumService()->createCurriculum($this->manager, [
            'subject_id' => $subject->id,
            'academic_level_id' => $level->id,
            'name' => 'Draft Only Curriculum',
        ]);

        $this->educationSystemService()->mapToCountry($this->manager, $system, $country);
        $this->educationSystemService()->mapToAcademicLevel($this->manager, $system, $level);
        $this->educationSystemService()->mapToCurriculum($this->manager, $system, $curriculum);

        $subjects = $this->resolver()->subjectsForContext($country, $system, $level);
        $this->assertTrue($subjects->contains('id', $subject->id));

        $curricula = $this->resolver()->curriculaForContext($country, $system, $level, $subject);
        $this->assertFalse($curricula->contains('id', $curriculum->id));
        $this->assertTrue($curricula->isEmpty());

        $this->expectException(AcademicContextException::class);
        $this->resolver()->resolveContext($country, $system, $level, $subject, $curriculum);
    }

    /**
     * Subject exists, no Curriculum exists at all: the Subject remains
     * regionally available from the Subject resolver step;
     * curriculaForContext() returns empty; no false resolved
     * AcademicContextData is produced.
     */
    public function test_subject_visible_with_no_curriculum_at_all(): void
    {
        $country = Country::factory()->create();
        $system = $this->educationSystemService()->createEducationSystem($this->manager, ['name' => 'No Curriculum Sys', 'slug' => 'no-curriculum-sys']);
        $level = $this->level('no-curriculum-level');
        $subject = $this->subject('no-curriculum-subject');

        $this->educationSystemService()->mapToCountry($this->manager, $system, $country);
        $this->educationSystemService()->mapToAcademicLevel($this->manager, $system, $level);
        // Deliberately no Curriculum row at all for this subject/level.

        $subjects = $this->resolver()->subjectsForContext($country, $system, $level);
        $this->assertTrue($subjects->contains('id', $subject->id));

        $curricula = $this->resolver()->curriculaForContext($country, $system, $level, $subject);
        $this->assertTrue($curricula->isEmpty());
    }

    /**
     * Published Curriculum exists: Subject appears, Curriculum
     * appears, and the Published CurriculumVersion resolves — the
     * fully-valid end-to-end case.
     */
    public function test_subject_and_curriculum_both_appear_when_curriculum_is_published(): void
    {
        $fixture = $this->buildFixture('FullyResolved');

        $subjects = $this->resolver()->subjectsForContext($fixture['country'], $fixture['system'], $fixture['level']);
        $this->assertTrue($subjects->contains('id', $fixture['subject']->id));

        $curricula = $this->resolver()->curriculaForContext($fixture['country'], $fixture['system'], $fixture['level'], $fixture['subject']);
        $this->assertTrue($curricula->contains('id', $fixture['curriculum']->id));

        $version = $this->resolver()->currentPublishedVersion($fixture['curriculum']);
        $this->assertNotNull($version);

        $context = $this->resolver()->resolveContext($fixture['country'], $fixture['system'], $fixture['level'], $fixture['subject'], $fixture['curriculum']);
        $this->assertSame($fixture['curriculum']->id, $context->curriculumId);
        $this->assertSame($version->id, $context->curriculumVersionId);
    }

    // ── Legacy compatibility: globally-applicable curricula ───────────────

    public function test_curriculum_with_no_education_system_mapping_is_globally_applicable(): void
    {
        $country = Country::factory()->create();
        $system = $this->educationSystemService()->createEducationSystem($this->manager, ['name' => 'Global Sys', 'slug' => 'global-sys']);
        $level = $this->level('global-level');
        $subject = $this->subject('global-subject');
        $curriculum = $this->publishedCurriculum($subject, $level, 'Legacy Curriculum');

        $this->educationSystemService()->mapToCountry($this->manager, $system, $country);
        $this->educationSystemService()->mapToAcademicLevel($this->manager, $system, $level);
        // Deliberately no curriculum mapping — this predates Education Systems.

        $context = $this->resolver()->resolveContext($country, $system, $level, $subject, $curriculum);
        $this->assertSame($curriculum->id, $context->curriculumId);
    }

    // ── Draft/Archived/Retired exclusion ───────────────────────────────────

    public function test_only_published_version_is_returned_never_draft_archived_or_retired(): void
    {
        $subject = $this->subject('lifecycle-subject');
        $level = $this->level('lifecycle-level');

        $curriculum = $this->curriculumService()->createCurriculum($this->manager, [
            'subject_id' => $subject->id,
            'academic_level_id' => $level->id,
            'name' => 'Lifecycle Curriculum',
        ]);

        $draft = $curriculum->latestVersion();
        $this->assertNull($this->resolver()->currentPublishedVersion($curriculum));

        $module = $this->curriculumService()->addModule($this->manager, $draft, ['title' => 'Module 1']);
        $topic = $this->topic($subject, 'lifecycle-topic');
        $this->curriculumService()->assignTopic($this->manager, $module, $topic);
        $this->curriculumService()->publish($this->manager, $draft);

        $curriculum->refresh();
        $published = $this->resolver()->currentPublishedVersion($curriculum);
        $this->assertNotNull($published);
        $this->assertSame(CurriculumVersionStatus::Published, $published->status);

        $this->curriculumService()->archive($this->manager, $published, 'superseded');
        $curriculum->refresh();
        $this->assertNull($this->resolver()->currentPublishedVersion($curriculum));
    }

    /**
     * Phase 1.1: publishing v2 now auto-archives v1 inside
     * CurriculumService::publish() itself, so the resolver naturally
     * sees only one Published row in the normal (non-corrupt) case —
     * still resolved by "highest version_number", never latest(id)/
     * latest(created_at).
     */
    public function test_current_published_version_is_highest_version_number_among_published(): void
    {
        $subject = $this->subject('multi-version-subject');
        $level = $this->level('multi-version-level');
        $topic = $this->topic($subject, 'multi-version-topic');

        $curriculum = $this->curriculumService()->createCurriculum($this->manager, [
            'subject_id' => $subject->id,
            'academic_level_id' => $level->id,
            'name' => 'Multi Version Curriculum',
        ]);

        $v1 = $curriculum->latestVersion();
        $module1 = $this->curriculumService()->addModule($this->manager, $v1, ['title' => 'Module 1']);
        $this->curriculumService()->assignTopic($this->manager, $module1, $topic);
        $this->curriculumService()->publish($this->manager, $v1);

        $v2 = $this->curriculumService()->createNewVersion($this->manager, $curriculum->refresh(), $v1);
        // v2 already cloned module/topic from v1 via cloneFrom.
        $this->curriculumService()->publish($this->manager, $v2);

        $curriculum->refresh();
        $v1->refresh();
        $this->assertSame(CurriculumVersionStatus::Archived, $v1->status);

        $current = $this->resolver()->currentPublishedVersion($curriculum);
        $this->assertSame($v2->id, $current->id);
        $this->assertSame(2, $current->version_number);
    }

    /**
     * Defensive/legacy-corrupt-data behavior: if a curriculum somehow
     * ends up with more than one Published row (state that
     * CurriculumService::publish() itself can no longer produce as of
     * Phase 1.1 — simulated here via direct model writes, bypassing
     * the service, the way genuinely corrupt legacy data would look),
     * the resolver still resolves deterministically to the highest
     * version_number rather than throwing or picking arbitrarily.
     */
    public function test_current_published_version_defends_against_corrupt_multiple_published_rows(): void
    {
        $subject = $this->subject('corrupt-subject');
        $level = $this->level('corrupt-level');
        $topic = $this->topic($subject, 'corrupt-topic');

        $curriculum = $this->curriculumService()->createCurriculum($this->manager, [
            'subject_id' => $subject->id,
            'academic_level_id' => $level->id,
            'name' => 'Corrupt Curriculum',
        ]);

        $v1 = $curriculum->latestVersion();
        $module1 = $this->curriculumService()->addModule($this->manager, $v1, ['title' => 'Module 1']);
        $this->curriculumService()->assignTopic($this->manager, $module1, $topic);
        $this->curriculumService()->publish($this->manager, $v1);

        $v2 = $this->curriculumService()->createNewVersion($this->manager, $curriculum->refresh(), $v1);
        $this->curriculumService()->publish($this->manager, $v2);

        // Simulate legacy/corrupt data: force v1 back to Published
        // directly on the model, bypassing CurriculumService entirely.
        $v1->refresh();
        $v1->forceFill(['status' => CurriculumVersionStatus::Published])->save();

        $curriculum->refresh();
        $current = $this->resolver()->currentPublishedVersion($curriculum);

        $this->assertNotNull($current);
        $this->assertSame($v2->id, $current->id);
        $this->assertSame(2, $current->version_number);
    }
}
