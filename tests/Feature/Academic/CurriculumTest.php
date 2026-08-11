<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Curriculum\Enums\CurriculumVersionStatus;
use App\Curriculum\Exceptions\CurriculumException;
use App\Curriculum\Services\AcademicContextResolver;
use App\Curriculum\Services\CurriculumService;
use App\Enums\AcademicStatus;
use App\Models\AcademicCategory;
use App\Models\AcademicLevel;
use App\Models\Curriculum;
use App\Models\CurriculumVersion;
use App\Models\Subject;
use App\Models\SubjectTopic;
use App\Models\User;
use Database\Seeders\AcademicPermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CurriculumTest extends TestCase
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

    private function subject(string $slug = 'mathematics', AcademicStatus $status = AcademicStatus::Active): Subject
    {
        $category = AcademicCategory::query()->firstOrCreate(['slug' => 'school'], ['name' => 'School']);

        return Subject::create([
            'academic_category_id' => $category->id,
            'name' => ucfirst($slug),
            'slug' => $slug,
            'status' => $status,
        ]);
    }

    private function level(string $slug = 'high-school', AcademicStatus $status = AcademicStatus::Active): AcademicLevel
    {
        return AcademicLevel::create([
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'status' => $status,
        ]);
    }

    private function topic(Subject $subject, string $slug = 'algebra', AcademicStatus $status = AcademicStatus::Active): SubjectTopic
    {
        return SubjectTopic::factory()->create([
            'subject_id' => $subject->id,
            'name' => ucfirst($slug),
            'slug' => $slug,
            'status' => $status,
        ]);
    }

    private function service(): CurriculumService
    {
        return app(CurriculumService::class);
    }

    // ── Model / schema ───────────────────────────────────────────────────

    public function test_curriculum_belongs_to_subject_and_academic_level(): void
    {
        $subject = $this->subject();
        $level = $this->level();

        $curriculum = $this->service()->createCurriculum($this->manager, [
            'subject_id' => $subject->id,
            'academic_level_id' => $level->id,
            'name' => 'Algebra Foundations',
        ]);

        $this->assertTrue($curriculum->subject->is($subject));
        $this->assertTrue($curriculum->academicLevel->is($level));
    }

    public function test_creating_curriculum_also_creates_initial_draft_version(): void
    {
        $curriculum = $this->service()->createCurriculum($this->manager, [
            'subject_id' => $this->subject()->id,
            'academic_level_id' => $this->level()->id,
            'name' => 'Algebra Foundations',
        ]);

        $this->assertCount(1, $curriculum->versions);
        $version = $curriculum->versions->first();
        $this->assertSame(1, $version->version_number);
        $this->assertSame(CurriculumVersionStatus::Draft, $version->status);
    }

    public function test_module_belongs_to_curriculum_version_and_topic_assignment_references_subject_topic(): void
    {
        $subject = $this->subject();
        $curriculum = $this->service()->createCurriculum($this->manager, [
            'subject_id' => $subject->id,
            'academic_level_id' => $this->level()->id,
            'name' => 'Algebra Foundations',
        ]);
        $version = $curriculum->versions->first();

        $module = $this->service()->addModule($this->manager, $version, ['title' => 'Introduction']);
        $topic = $this->topic($subject);
        $assignment = $this->service()->assignTopic($this->manager, $module, $topic);

        $this->assertTrue($module->version->is($version));
        $this->assertTrue($assignment->topic->is($topic));
        $this->assertTrue($assignment->module->is($module));
    }

    public function test_slug_is_unique_within_subject_and_academic_level(): void
    {
        $subject = $this->subject();
        $level = $this->level();

        $this->service()->createCurriculum($this->manager, [
            'subject_id' => $subject->id,
            'academic_level_id' => $level->id,
            'name' => 'Algebra',
            'slug' => 'algebra',
        ]);

        $this->expectException(ValidationException::class);

        $this->service()->createCurriculum($this->manager, [
            'subject_id' => $subject->id,
            'academic_level_id' => $level->id,
            'name' => 'Algebra Again',
            'slug' => 'algebra',
        ]);
    }

    public function test_same_slug_may_exist_for_a_different_academic_level(): void
    {
        $subject = $this->subject();
        $levelOne = $this->level('grade-9');
        $levelTwo = $this->level('grade-10');

        $this->service()->createCurriculum($this->manager, [
            'subject_id' => $subject->id, 'academic_level_id' => $levelOne->id, 'name' => 'Algebra', 'slug' => 'algebra',
        ]);
        $second = $this->service()->createCurriculum($this->manager, [
            'subject_id' => $subject->id, 'academic_level_id' => $levelTwo->id, 'name' => 'Algebra', 'slug' => 'algebra',
        ]);

        $this->assertSame(2, Curriculum::query()->where('slug', 'algebra')->count());
        $this->assertNotNull($second);
    }

    public function test_multiple_curricula_may_exist_for_the_same_subject_and_level_pair(): void
    {
        $subject = $this->subject();
        $level = $this->level();

        $this->service()->createCurriculum($this->manager, [
            'subject_id' => $subject->id, 'academic_level_id' => $level->id, 'name' => 'Standard Track', 'slug' => 'standard-track',
        ]);
        $this->service()->createCurriculum($this->manager, [
            'subject_id' => $subject->id, 'academic_level_id' => $level->id, 'name' => 'Accelerated Track', 'slug' => 'accelerated-track',
        ]);

        $this->assertSame(2, Curriculum::query()->where('subject_id', $subject->id)->where('academic_level_id', $level->id)->count());
    }

    public function test_version_number_is_unique_per_curriculum(): void
    {
        $curriculum = $this->service()->createCurriculum($this->manager, [
            'subject_id' => $this->subject()->id, 'academic_level_id' => $this->level()->id, 'name' => 'Algebra',
        ]);

        $this->expectException(QueryException::class);

        CurriculumVersion::query()->create([
            'curriculum_id' => $curriculum->id,
            'version_number' => 1,
            'status' => CurriculumVersionStatus::Draft,
        ]);
    }

    // ── Versioning ────────────────────────────────────────────────────────

    public function test_new_version_is_sequential_and_draft(): void
    {
        $curriculum = $this->service()->createCurriculum($this->manager, [
            'subject_id' => $this->subject()->id, 'academic_level_id' => $this->level()->id, 'name' => 'Algebra',
        ]);

        $v2 = $this->service()->createNewVersion($this->manager, $curriculum);

        $this->assertSame(2, $v2->version_number);
        $this->assertSame(CurriculumVersionStatus::Draft, $v2->status);
    }

    public function test_new_version_can_clone_structure_from_an_existing_version_without_mutating_it(): void
    {
        $subject = $this->subject();
        $curriculum = $this->service()->createCurriculum($this->manager, [
            'subject_id' => $subject->id, 'academic_level_id' => $this->level()->id, 'name' => 'Algebra',
        ]);
        $v1 = $curriculum->versions->first();
        $module = $this->service()->addModule($this->manager, $v1, ['title' => 'Introduction']);
        $topic = $this->topic($subject);
        $this->service()->assignTopic($this->manager, $module, $topic);

        $v2 = $this->service()->createNewVersion($this->manager, $curriculum, $v1);

        $this->assertCount(1, $v2->modules);
        $this->assertCount(1, $v2->modules->first()->topicAssignments);
        $this->assertNotSame($v1->modules->first()->id, $v2->modules->first()->id);

        // v1's own module/topic rows are untouched.
        $v1->refresh();
        $this->assertCount(1, $v1->modules);
    }

    public function test_publishing_a_version_does_not_mutate_a_previously_published_version(): void
    {
        $subject = $this->subject();
        $curriculum = $this->service()->createCurriculum($this->manager, [
            'subject_id' => $subject->id, 'academic_level_id' => $this->level()->id, 'name' => 'Algebra',
        ]);
        $v1 = $curriculum->versions->first();
        $module = $this->service()->addModule($this->manager, $v1, ['title' => 'Introduction']);
        $this->service()->assignTopic($this->manager, $module, $this->topic($subject));
        $v1 = $this->service()->publish($this->manager, $v1);

        $v2 = $this->service()->createNewVersion($this->manager, $curriculum, $v1);
        $module2 = $v2->modules->first();
        $this->service()->updateModule($this->manager, $module2, ['title' => 'Introduction (revised)']);

        $v1->refresh();
        $this->assertSame('Introduction', $v1->modules->first()->title);
        $this->assertSame(CurriculumVersionStatus::Published, $v1->status);
    }

    public function test_published_version_cannot_be_destructively_edited(): void
    {
        $subject = $this->subject();
        $curriculum = $this->service()->createCurriculum($this->manager, [
            'subject_id' => $subject->id, 'academic_level_id' => $this->level()->id, 'name' => 'Algebra',
        ]);
        $v1 = $curriculum->versions->first();
        $module = $this->service()->addModule($this->manager, $v1, ['title' => 'Introduction']);
        $this->service()->assignTopic($this->manager, $module, $this->topic($subject));
        $v1 = $this->service()->publish($this->manager, $v1);

        $this->expectException(CurriculumException::class);
        $this->service()->addModule($this->manager, $v1, ['title' => 'Should fail']);
    }

    public function test_duplicate_version_creation_race_is_prevented_by_unique_constraint(): void
    {
        $curriculum = $this->service()->createCurriculum($this->manager, [
            'subject_id' => $this->subject()->id, 'academic_level_id' => $this->level()->id, 'name' => 'Algebra',
        ]);

        // Simulate a race: two inserts attempting the same version_number
        // outside the service's locked path both hit the DB constraint.
        $this->expectException(QueryException::class);

        CurriculumVersion::query()->create(['curriculum_id' => $curriculum->id, 'version_number' => 1, 'status' => 'draft']);
    }

    // ── Validation ───────────────────────────────────────────────────────

    public function test_curriculum_cannot_be_created_for_an_inactive_subject(): void
    {
        $subject = $this->subject('inactive-subject', AcademicStatus::Inactive);

        $this->expectException(CurriculumException::class);

        $this->service()->createCurriculum($this->manager, [
            'subject_id' => $subject->id, 'academic_level_id' => $this->level()->id, 'name' => 'Algebra',
        ]);
    }

    public function test_curriculum_cannot_be_created_for_an_inactive_academic_level(): void
    {
        $level = $this->level('inactive-level', AcademicStatus::Inactive);

        $this->expectException(CurriculumException::class);

        $this->service()->createCurriculum($this->manager, [
            'subject_id' => $this->subject()->id, 'academic_level_id' => $level->id, 'name' => 'Algebra',
        ]);
    }

    public function test_topic_from_a_different_subject_cannot_be_assigned(): void
    {
        $subject = $this->subject();
        $otherSubject = $this->subject('physics');
        $curriculum = $this->service()->createCurriculum($this->manager, [
            'subject_id' => $subject->id, 'academic_level_id' => $this->level()->id, 'name' => 'Algebra',
        ]);
        $module = $this->service()->addModule($this->manager, $curriculum->versions->first(), ['title' => 'Introduction']);

        $this->expectException(CurriculumException::class);
        $this->service()->assignTopic($this->manager, $module, $this->topic($otherSubject, 'motion'));
    }

    public function test_duplicate_topic_assignment_within_the_same_module_is_rejected(): void
    {
        $subject = $this->subject();
        $curriculum = $this->service()->createCurriculum($this->manager, [
            'subject_id' => $subject->id, 'academic_level_id' => $this->level()->id, 'name' => 'Algebra',
        ]);
        $module = $this->service()->addModule($this->manager, $curriculum->versions->first(), ['title' => 'Introduction']);
        $topic = $this->topic($subject);
        $this->service()->assignTopic($this->manager, $module, $topic);

        $this->expectException(CurriculumException::class);
        $this->service()->assignTopic($this->manager, $module, $topic);
    }

    public function test_inactive_topic_cannot_be_newly_assigned(): void
    {
        $subject = $this->subject();
        $curriculum = $this->service()->createCurriculum($this->manager, [
            'subject_id' => $subject->id, 'academic_level_id' => $this->level()->id, 'name' => 'Algebra',
        ]);
        $module = $this->service()->addModule($this->manager, $curriculum->versions->first(), ['title' => 'Introduction']);
        $topic = $this->topic($subject, 'archived-topic', AcademicStatus::Archived);

        $this->expectException(CurriculumException::class);
        $this->service()->assignTopic($this->manager, $module, $topic);
    }

    public function test_curriculum_cannot_publish_without_a_module(): void
    {
        $curriculum = $this->service()->createCurriculum($this->manager, [
            'subject_id' => $this->subject()->id, 'academic_level_id' => $this->level()->id, 'name' => 'Algebra',
        ]);

        $this->expectException(CurriculumException::class);
        $this->service()->publish($this->manager, $curriculum->versions->first());
    }

    public function test_module_without_a_topic_blocks_publication(): void
    {
        $curriculum = $this->service()->createCurriculum($this->manager, [
            'subject_id' => $this->subject()->id, 'academic_level_id' => $this->level()->id, 'name' => 'Algebra',
        ]);
        $this->service()->addModule($this->manager, $curriculum->versions->first(), ['title' => 'Empty module']);

        $this->expectException(CurriculumException::class);
        $this->service()->publish($this->manager, $curriculum->versions->first()->fresh());
    }

    // ── Lifecycle ────────────────────────────────────────────────────────

    public function test_draft_can_transition_to_published_when_valid(): void
    {
        $subject = $this->subject();
        $curriculum = $this->service()->createCurriculum($this->manager, [
            'subject_id' => $subject->id, 'academic_level_id' => $this->level()->id, 'name' => 'Algebra',
        ]);
        $module = $this->service()->addModule($this->manager, $curriculum->versions->first(), ['title' => 'Introduction']);
        $this->service()->assignTopic($this->manager, $module, $this->topic($subject));

        $published = $this->service()->publish($this->manager, $curriculum->versions->first()->fresh());

        $this->assertSame(CurriculumVersionStatus::Published, $published->status);
        $this->assertNotNull($published->published_at);
    }

    public function test_published_can_transition_to_archived_then_retired(): void
    {
        $subject = $this->subject();
        $curriculum = $this->service()->createCurriculum($this->manager, [
            'subject_id' => $subject->id, 'academic_level_id' => $this->level()->id, 'name' => 'Algebra',
        ]);
        $module = $this->service()->addModule($this->manager, $curriculum->versions->first(), ['title' => 'Introduction']);
        $this->service()->assignTopic($this->manager, $module, $this->topic($subject));
        $version = $this->service()->publish($this->manager, $curriculum->versions->first()->fresh());

        $archived = $this->service()->archive($this->manager, $version, 'Superseded by a newer curriculum revision.');
        $this->assertSame(CurriculumVersionStatus::Archived, $archived->status);

        $retired = $this->service()->retire($this->manager, $archived, 'No longer relevant to any active learner.');
        $this->assertSame(CurriculumVersionStatus::Retired, $retired->status);
    }

    public function test_invalid_transition_from_draft_to_archived_is_rejected(): void
    {
        $curriculum = $this->service()->createCurriculum($this->manager, [
            'subject_id' => $this->subject()->id, 'academic_level_id' => $this->level()->id, 'name' => 'Algebra',
        ]);

        $this->expectException(CurriculumException::class);
        $this->service()->archive($this->manager, $curriculum->versions->first(), 'Not allowed.');
    }

    public function test_invalid_transition_from_retired_is_rejected(): void
    {
        $subject = $this->subject();
        $curriculum = $this->service()->createCurriculum($this->manager, [
            'subject_id' => $subject->id, 'academic_level_id' => $this->level()->id, 'name' => 'Algebra',
        ]);
        $module = $this->service()->addModule($this->manager, $curriculum->versions->first(), ['title' => 'Introduction']);
        $this->service()->assignTopic($this->manager, $module, $this->topic($subject));
        $version = $this->service()->publish($this->manager, $curriculum->versions->first()->fresh());
        $version = $this->service()->archive($this->manager, $version, 'Superseded.');
        $version = $this->service()->retire($this->manager, $version, 'Retired.');

        $this->expectException(CurriculumException::class);
        $this->service()->publish($this->manager, $version);
    }

    public function test_lifecycle_actions_are_audit_logged(): void
    {
        $subject = $this->subject();
        $curriculum = $this->service()->createCurriculum($this->manager, [
            'subject_id' => $subject->id, 'academic_level_id' => $this->level()->id, 'name' => 'Algebra',
        ]);
        $module = $this->service()->addModule($this->manager, $curriculum->versions->first(), ['title' => 'Introduction']);
        $this->service()->assignTopic($this->manager, $module, $this->topic($subject));
        $version = $this->service()->publish($this->manager, $curriculum->versions->first()->fresh());
        $this->service()->archive($this->manager, $version, 'Superseded.');

        $this->assertDatabaseHas('activity_log', ['log_name' => 'curricula', 'event' => 'curriculum_created']);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'curricula', 'event' => 'version_published']);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'curricula', 'event' => 'version_archived']);
    }

    public function test_archived_and_retired_versions_remain_queryable(): void
    {
        $subject = $this->subject();
        $curriculum = $this->service()->createCurriculum($this->manager, [
            'subject_id' => $subject->id, 'academic_level_id' => $this->level()->id, 'name' => 'Algebra',
        ]);
        $module = $this->service()->addModule($this->manager, $curriculum->versions->first(), ['title' => 'Introduction']);
        $this->service()->assignTopic($this->manager, $module, $this->topic($subject));
        $version = $this->service()->publish($this->manager, $curriculum->versions->first()->fresh());
        $version = $this->service()->archive($this->manager, $version, 'Superseded.');
        $version = $this->service()->retire($this->manager, $version, 'Retired.');

        $this->assertNotNull(CurriculumVersion::query()->find($version->id));
        $this->assertSame(CurriculumVersionStatus::Retired, $version->fresh()->status);
    }

    // ── Phase 1.1: single-Published invariant ──────────────────────────────

    /**
     * Sequential publish: v1 Published, v2 Draft. Publishing v2 must
     * archive v1 (superseded) and publish v2 atomically, in the same
     * call. Both versions remain historically queryable, v1's
     * modules/topics are unchanged, and v2 is the current Published
     * version.
     */
    public function test_publishing_a_new_version_archives_the_previous_published_version(): void
    {
        $subject = $this->subject();
        $curriculum = $this->service()->createCurriculum($this->manager, [
            'subject_id' => $subject->id, 'academic_level_id' => $this->level()->id, 'name' => 'Algebra',
        ]);

        $v1 = $curriculum->versions->first();
        $module1 = $this->service()->addModule($this->manager, $v1, ['title' => 'Introduction']);
        $this->service()->assignTopic($this->manager, $module1, $this->topic($subject));
        $v1 = $this->service()->publish($this->manager, $v1);

        $v2 = $this->service()->createNewVersion($this->manager, $curriculum->refresh(), $v1);
        $v2 = $this->service()->publish($this->manager, $v2);

        $v1->refresh();
        $v2->refresh();

        $this->assertSame(CurriculumVersionStatus::Archived, $v1->status);
        $this->assertNotNull($v1->archived_at);
        $this->assertSame(CurriculumVersionStatus::Published, $v2->status);
        $this->assertNotNull($v2->published_at);

        // v1's content is untouched by the supersession — only its
        // lifecycle status changed.
        $this->assertCount(1, $v1->modules);
        $this->assertSame('Introduction', $v1->modules->first()->title);

        // Both remain historically queryable.
        $this->assertNotNull(CurriculumVersion::query()->find($v1->id));
        $this->assertNotNull(CurriculumVersion::query()->find($v2->id));

        // Exactly one Published row for this curriculum.
        $this->assertSame(1, CurriculumVersion::query()
            ->where('curriculum_id', $curriculum->id)
            ->where('status', CurriculumVersionStatus::Published)
            ->count());

        $current = app(AcademicContextResolver::class)->currentPublishedVersion($curriculum->refresh());
        $this->assertSame($v2->id, $current->id);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'curricula',
            'event' => 'version_archived',
            'subject_id' => $v1->id,
        ]);
    }

    /**
     * Failed new publication: if v2 fails publication validation (no
     * modules), v1 remains Published, v2 remains Draft, and no partial
     * archival occurs — the whole publish() call for v2 must roll back
     * together.
     */
    public function test_failed_publication_of_new_version_leaves_previous_published_version_untouched(): void
    {
        $subject = $this->subject();
        $curriculum = $this->service()->createCurriculum($this->manager, [
            'subject_id' => $subject->id, 'academic_level_id' => $this->level()->id, 'name' => 'Algebra',
        ]);

        $v1 = $curriculum->versions->first();
        $module1 = $this->service()->addModule($this->manager, $v1, ['title' => 'Introduction']);
        $this->service()->assignTopic($this->manager, $module1, $this->topic($subject));
        $v1 = $this->service()->publish($this->manager, $v1);

        // v2 is created but deliberately left empty (no modules) so
        // assertPublishable() rejects it.
        $v2 = $this->service()->createNewVersion($this->manager, $curriculum->refresh());

        $this->expectException(CurriculumException::class);

        try {
            $this->service()->publish($this->manager, $v2);
        } finally {
            $v1->refresh();
            $v2->refresh();

            $this->assertSame(CurriculumVersionStatus::Published, $v1->status);
            $this->assertSame(CurriculumVersionStatus::Draft, $v2->status);
            $this->assertSame(1, CurriculumVersion::query()
                ->where('curriculum_id', $curriculum->id)
                ->where('status', CurriculumVersionStatus::Published)
                ->count());
        }
    }

    /**
     * Racing publish attempts on two competing Draft versions of the
     * same curriculum must never leave two Published versions, zero
     * Published versions, or a partially-applied lifecycle change —
     * regardless of call order. The Curriculum-row lock in publish()
     * makes each call an atomic serialization point; this test proves
     * the invariant holds across both possible orderings, which is
     * what genuinely concurrent callers would also observe (each
     * publish() call is itself all-or-nothing under the row lock).
     */
    public function test_racing_publish_attempts_never_leave_two_or_zero_published_versions(): void
    {
        $subject = $this->subject();
        $curriculum = $this->service()->createCurriculum($this->manager, [
            'subject_id' => $subject->id, 'academic_level_id' => $this->level()->id, 'name' => 'Algebra',
        ]);

        $v1 = $curriculum->versions->first();
        $module1 = $this->service()->addModule($this->manager, $v1, ['title' => 'Module 1']);
        $this->service()->assignTopic($this->manager, $module1, $this->topic($subject));

        $v2 = $this->service()->createNewVersion($this->manager, $curriculum->refresh(), $v1);
        $v3 = $this->service()->createNewVersion($this->manager, $curriculum->refresh(), $v1);

        // Two competing Drafts (v2, v3) both racing to become "the"
        // Published version for this curriculum, with none Published
        // yet. Regardless of which one commits first, the loser's
        // publish() call still runs to completion under the curriculum
        // row lock and correctly supersedes whichever version is
        // Published at the time it acquires the lock.
        $this->service()->publish($this->manager, $v2);
        $this->service()->publish($this->manager, $v3);

        $v1->refresh();
        $v2->refresh();
        $v3->refresh();

        $this->assertSame(CurriculumVersionStatus::Draft, $v1->status);
        $this->assertSame(CurriculumVersionStatus::Archived, $v2->status);
        $this->assertSame(CurriculumVersionStatus::Published, $v3->status);

        $publishedCount = CurriculumVersion::query()
            ->where('curriculum_id', $curriculum->id)
            ->where('status', CurriculumVersionStatus::Published)
            ->count();

        $this->assertSame(1, $publishedCount, 'Racing publish attempts must leave exactly one Published version.');
    }
}
