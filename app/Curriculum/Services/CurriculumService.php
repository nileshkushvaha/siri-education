<?php

declare(strict_types=1);

namespace App\Curriculum\Services;

use App\Curriculum\Enums\CurriculumVersionStatus;
use App\Curriculum\Exceptions\CurriculumException;
use App\Enums\AcademicStatus;
use App\Models\AcademicLevel;
use App\Models\Curriculum;
use App\Models\CurriculumModule;
use App\Models\CurriculumModuleTopic;
use App\Models\CurriculumVersion;
use App\Models\Subject;
use App\Models\SubjectTopic;
use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The single authoritative writer of the Curriculum → CurriculumVersion
 * → CurriculumModule → SubjectTopic assignment structure (SRS Book 2
 * Chapter 4/5, Phase 0A). Filament calls this service exclusively —
 * no lifecycle field or structural mutation happens directly on the
 * models from a Resource/Page. Every mutation runs inside a
 * transaction; version-affecting operations row-lock the
 * CurriculumVersion first so concurrent admin actions cannot corrupt
 * sequencing or double-publish.
 */
final class CurriculumService
{
    private const LOG_NAME = 'curricula';

    public function __construct(
        private readonly AuditTrailService $audit,
    ) {}

    // ── Curriculum identity ──────────────────────────────────────────────

    /**
     * Creates the Curriculum identity row and its initial Draft
     * version (version 1) together — a Curriculum with no version at
     * all is not a meaningful object in this domain.
     *
     * @param  array{subject_id: string, academic_level_id: string, name: string, slug?: string|null, description?: string|null}  $data
     */
    public function createCurriculum(User $admin, array $data): Curriculum
    {
        $this->assertCan($admin, 'create', Curriculum::class);

        $subject = $this->activeSubject((string) $data['subject_id']);
        $level = $this->activeAcademicLevel((string) $data['academic_level_id']);

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'A curriculum name is required.']);
        }

        $slug = trim((string) ($data['slug'] ?? '')) ?: Str::slug($name);

        return DB::transaction(function () use ($admin, $subject, $level, $name, $slug, $data): Curriculum {
            $this->assertUniqueSlug($subject->id, $level->id, $slug);

            $curriculum = Curriculum::query()->create([
                'subject_id' => $subject->id,
                'academic_level_id' => $level->id,
                'name' => $name,
                'slug' => $slug,
                'description' => $data['description'] ?? null,
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]);

            $version = $curriculum->versions()->create([
                'version_number' => 1,
                'status' => CurriculumVersionStatus::Draft,
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]);

            $this->audit->logUser($admin, self::LOG_NAME, 'curriculum_created', sprintf('Curriculum "%s" created.', $curriculum->name), $curriculum, [
                'subject_id' => $subject->id,
                'academic_level_id' => $level->id,
            ]);

            $this->audit->logUser($admin, self::LOG_NAME, 'version_created', sprintf('Curriculum "%s" version %d created as draft.', $curriculum->name, $version->version_number), $version, [
                'curriculum_id' => $curriculum->id,
                'version_number' => $version->version_number,
            ]);

            return $curriculum->refresh()->load('versions');
        });
    }

    /**
     * @param  array{name?: string, slug?: string, description?: string|null}  $data
     */
    public function updateCurriculum(User $admin, Curriculum $curriculum, array $data): Curriculum
    {
        $this->assertCan($admin, 'update', $curriculum);

        $attributes = collect($data)->only(['name', 'slug', 'description'])->all();

        if (array_key_exists('name', $attributes) && trim((string) $attributes['name']) === '') {
            throw ValidationException::withMessages(['name' => 'A curriculum name is required.']);
        }

        return DB::transaction(function () use ($admin, $curriculum, $attributes): Curriculum {
            $slug = $attributes['slug'] ?? $curriculum->slug;
            $this->assertUniqueSlug($curriculum->subject_id, $curriculum->academic_level_id, $slug, $curriculum->id);

            $curriculum->fill([...$attributes, 'updated_by' => $admin->id])->save();

            $this->audit->logUser($admin, self::LOG_NAME, 'curriculum_updated', sprintf('Curriculum "%s" updated.', $curriculum->name), $curriculum, [
                'changed_fields' => array_keys($attributes),
            ]);

            return $curriculum->refresh();
        });
    }

    // ── Version lifecycle ────────────────────────────────────────────────

    /**
     * Creates a new Draft version for a curriculum, sequential and
     * unique (version_number = max+1 under a row lock — races cannot
     * produce duplicate numbers). Optionally clones the structure
     * (modules + topic assignments) of an existing version as a
     * starting point; the source version's own rows are never
     * mutated.
     */
    public function createNewVersion(User $admin, Curriculum $curriculum, ?CurriculumVersion $cloneFrom = null): CurriculumVersion
    {
        $this->assertCan($admin, 'create', CurriculumVersion::class);

        return DB::transaction(function () use ($admin, $curriculum, $cloneFrom): CurriculumVersion {
            // Lock the curriculum row itself so two concurrent "create
            // new version" requests serialize rather than both reading
            // the same max(version_number).
            $lockedCurriculum = Curriculum::query()->whereKey($curriculum->id)->lockForUpdate()->firstOrFail();

            $nextNumber = (int) $lockedCurriculum->versions()->lockForUpdate()->max('version_number') + 1;

            $version = $lockedCurriculum->versions()->create([
                'version_number' => $nextNumber,
                'status' => CurriculumVersionStatus::Draft,
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]);

            if ($cloneFrom !== null) {
                if ($cloneFrom->curriculum_id !== $lockedCurriculum->id) {
                    throw new CurriculumException('Cannot clone a version from a different curriculum.');
                }

                $this->cloneStructure($cloneFrom, $version, $admin);
            }

            $this->audit->logUser($admin, self::LOG_NAME, 'version_created', sprintf('Curriculum "%s" version %d created.', $lockedCurriculum->name, $version->version_number), $version, [
                'curriculum_id' => $lockedCurriculum->id,
                'version_number' => $version->version_number,
                'cloned_from_version_id' => $cloneFrom?->id,
            ]);

            return $version->refresh()->load('modules.topicAssignments');
        });
    }

    /**
     * @param  array{notes?: string|null}  $data
     */
    public function updateDraft(User $admin, CurriculumVersion $version, array $data): CurriculumVersion
    {
        $this->assertCan($admin, 'update', $version);
        $this->assertDraft($version);

        $version->fill([
            'notes' => $data['notes'] ?? $version->notes,
            'updated_by' => $admin->id,
        ])->save();

        $this->audit->logUser($admin, self::LOG_NAME, 'version_updated', sprintf('Curriculum version %d updated.', $version->version_number), $version, []);

        return $version->refresh();
    }

    /**
     * Publishes a Draft version and enforces the write-side invariant
     * that at most one CurriculumVersion may be Published for a given
     * Curriculum at a time (Phase 1.1 correction — Phase 0A/1 left this
     * to a resolver-side "pick highest version_number" workaround,
     * which only compensated for an invalid state instead of
     * preventing it). This is the Phase 0A/1-compatible subset of the
     * full SRS rule — SRS Book 2 §5.8/§4.9 ultimately scopes "one
     * active version for new enrollment" to Subject + AcademicLevel +
     * RoadmapType, but RoadmapType does not exist yet, so this method
     * scopes the invariant to curriculum_id only.
     *
     * The parent Curriculum row is locked first so two concurrent
     * publish attempts for the same curriculum (e.g. two Drafts
     * racing to become "the" Published version) serialize rather than
     * both reading/writing a "no other Published version exists" state
     * that is no longer true by the time they act on it. Any existing
     * Published version(s) for this curriculum are transitioned to
     * Archived — using the same forward-only lifecycle rule already
     * enforced by assertTransition() — before the new version is
     * published, all inside one transaction: either both lifecycle
     * changes commit together, or (on any validation failure) neither
     * does, so there is never an intermediate committed state with the
     * old version archived and the new one still unpublished, nor one
     * with two concurrently Published versions.
     *
     * Superseded content is never mutated — only the previous version's
     * status/archived_at change; its modules/topics are left exactly
     * as they were, so historical student records that reference it
     * remain intact (SRS §5.8 "historical student records remain
     * linked to the curriculum version active when learning occurred").
     */
    public function publish(User $admin, CurriculumVersion $version): CurriculumVersion
    {
        $this->assertCan($admin, 'update', $version);

        return DB::transaction(function () use ($admin, $version): CurriculumVersion {
            // Establish the serialization boundary: lock the parent
            // Curriculum before touching any of its versions.
            $curriculum = Curriculum::query()->whereKey($version->curriculum_id)->lockForUpdate()->firstOrFail();

            $locked = $this->lockedVersion($version);

            $this->assertTransition($locked, CurriculumVersionStatus::Published);
            $this->assertPublishable($locked);

            // Find (and lock) any version(s) of this curriculum still
            // Published — normally at most one, but locked/handled as a
            // set so pre-existing corrupt/legacy data self-heals instead
            // of blocking the new publish.
            $previouslyPublished = CurriculumVersion::query()
                ->where('curriculum_id', $curriculum->id)
                ->where('status', CurriculumVersionStatus::Published)
                ->whereKeyNot($locked->id)
                ->lockForUpdate()
                ->get();

            foreach ($previouslyPublished as $previous) {
                $this->assertTransition($previous, CurriculumVersionStatus::Archived);

                $previous->forceFill([
                    'status' => CurriculumVersionStatus::Archived,
                    'archived_at' => now(),
                    'updated_by' => $admin->id,
                ])->save();

                $this->audit->logUser($admin, self::LOG_NAME, 'version_archived', sprintf(
                    'Curriculum version %d archived — superseded by version %d.',
                    $previous->version_number,
                    $locked->version_number,
                ), $previous, [
                    'curriculum_id' => $curriculum->id,
                    'reason' => 'superseded_by_version',
                    'superseded_by_version_id' => $locked->id,
                    'superseded_by_version_number' => $locked->version_number,
                ]);
            }

            $locked->forceFill([
                'status' => CurriculumVersionStatus::Published,
                'published_at' => now(),
                'updated_by' => $admin->id,
            ])->save();

            $this->audit->logUser($admin, self::LOG_NAME, 'version_published', sprintf('Curriculum version %d published.', $locked->version_number), $locked, [
                'curriculum_id' => $locked->curriculum_id,
                'version_number' => $locked->version_number,
                'superseded_version_ids' => $previouslyPublished->pluck('id')->values()->all(),
            ]);

            return $locked->refresh();
        });
    }

    public function archive(User $admin, CurriculumVersion $version, string $reason): CurriculumVersion
    {
        return $this->transitionVersion($admin, $version, CurriculumVersionStatus::Archived, $reason, 'version_archived', ['archived_at' => now()]);
    }

    public function retire(User $admin, CurriculumVersion $version, string $reason): CurriculumVersion
    {
        return $this->transitionVersion($admin, $version, CurriculumVersionStatus::Retired, $reason, 'version_retired', ['retired_at' => now()]);
    }

    // ── Modules ──────────────────────────────────────────────────────────

    /**
     * @param  array{title: string, description?: string|null, sort_order?: int|null}  $data
     */
    public function addModule(User $admin, CurriculumVersion $version, array $data): CurriculumModule
    {
        $this->assertCan($admin, 'create', CurriculumModule::class);
        $this->assertDraft($version);

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw ValidationException::withMessages(['title' => 'Module title is required.']);
        }

        return DB::transaction(function () use ($admin, $version, $title, $data): CurriculumModule {
            $locked = $this->lockedVersion($version);
            $this->assertDraft($locked);

            $nextOrder = $data['sort_order'] ?? ((int) $locked->modules()->max('sort_order') + 1);

            $module = $locked->modules()->create([
                'title' => $title,
                'description' => $data['description'] ?? null,
                'sort_order' => $nextOrder,
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]);

            $this->audit->logUser($admin, self::LOG_NAME, 'module_added', sprintf('Module "%s" added to curriculum version %d.', $module->title, $locked->version_number), $module, [
                'curriculum_version_id' => $locked->id,
            ]);

            return $module;
        });
    }

    /**
     * @param  array{title?: string, description?: string|null}  $data
     */
    public function updateModule(User $admin, CurriculumModule $module, array $data): CurriculumModule
    {
        $this->assertCan($admin, 'update', $module);
        $this->assertDraft($module->version);

        $attributes = collect($data)->only(['title', 'description'])->all();

        if (array_key_exists('title', $attributes) && trim((string) $attributes['title']) === '') {
            throw ValidationException::withMessages(['title' => 'Module title is required.']);
        }

        $module->fill([...$attributes, 'updated_by' => $admin->id])->save();

        $this->audit->logUser($admin, self::LOG_NAME, 'module_updated', sprintf('Module "%s" updated.', $module->title), $module, [
            'changed_fields' => array_keys($attributes),
        ]);

        return $module->refresh();
    }

    public function removeModule(User $admin, CurriculumModule $module): void
    {
        $this->assertCan($admin, 'delete', $module);
        $this->assertDraft($module->version);

        DB::transaction(function () use ($admin, $module): void {
            $title = $module->title;
            $versionId = $module->curriculum_version_id;

            $module->delete();

            $this->audit->logUser($admin, self::LOG_NAME, 'module_removed', sprintf('Module "%s" removed.', $title), null, [
                'curriculum_version_id' => $versionId,
            ]);
        });
    }

    /** @param  list<string>  $orderedModuleIds */
    public function reorderModules(User $admin, CurriculumVersion $version, array $orderedModuleIds): void
    {
        $this->assertCan($admin, 'update', CurriculumModule::class);
        $this->assertDraft($version);

        DB::transaction(function () use ($admin, $version, $orderedModuleIds): void {
            $locked = $this->lockedVersion($version);
            $this->assertDraft($locked);

            $modules = $locked->modules()->whereIn('id', $orderedModuleIds)->get()->keyBy('id');

            if ($modules->count() !== count($orderedModuleIds)) {
                throw new CurriculumException('One or more modules do not belong to this curriculum version.');
            }

            foreach (array_values($orderedModuleIds) as $index => $moduleId) {
                $modules->get($moduleId)?->update(['sort_order' => $index, 'updated_by' => $admin->id]);
            }

            $this->audit->logUser($admin, self::LOG_NAME, 'modules_reordered', sprintf('Modules reordered for curriculum version %d.', $locked->version_number), $locked, []);
        });
    }

    // ── Topic assignment ─────────────────────────────────────────────────

    public function assignTopic(User $admin, CurriculumModule $module, SubjectTopic $topic, ?int $sortOrder = null): CurriculumModuleTopic
    {
        $this->assertCan($admin, 'update', $module);
        $this->assertDraft($module->version);

        return DB::transaction(function () use ($admin, $module, $topic, $sortOrder): CurriculumModuleTopic {
            $version = $module->version()->lockForUpdate()->firstOrFail();
            $this->assertDraft($version);

            $curriculum = $version->curriculum;

            if ($topic->subject_id !== $curriculum->subject_id) {
                throw new CurriculumException('This topic belongs to a different subject and cannot be assigned to this curriculum.');
            }

            if ($topic->status !== AcademicStatus::Active) {
                throw new CurriculumException('Only active subject topics may be newly assigned.');
            }

            if ($module->topicAssignments()->where('subject_topic_id', $topic->id)->exists()) {
                throw new CurriculumException(sprintf('Topic "%s" is already assigned to this module.', $topic->name));
            }

            $nextOrder = $sortOrder ?? ((int) $module->topicAssignments()->max('sort_order') + 1);

            $pivot = $module->topicAssignments()->create([
                'subject_topic_id' => $topic->id,
                'sort_order' => $nextOrder,
                'created_by' => $admin->id,
            ]);

            $this->audit->logUser($admin, self::LOG_NAME, 'topic_assigned', sprintf('Topic "%s" assigned to module "%s".', $topic->name, $module->title), $pivot, [
                'curriculum_module_id' => $module->id,
                'subject_topic_id' => $topic->id,
            ]);

            return $pivot;
        });
    }

    public function unassignTopic(User $admin, CurriculumModuleTopic $assignment): void
    {
        $module = $assignment->module;
        $this->assertCan($admin, 'update', $module);
        $this->assertDraft($module->version);

        DB::transaction(function () use ($admin, $assignment, $module): void {
            $topicName = $assignment->topic?->name ?? (string) $assignment->subject_topic_id;
            $assignment->delete();

            $this->audit->logUser($admin, self::LOG_NAME, 'topic_unassigned', sprintf('Topic "%s" removed from module "%s".', $topicName, $module->title), null, [
                'curriculum_module_id' => $module->id,
            ]);
        });
    }

    /** @param  list<string>  $orderedAssignmentIds */
    public function reorderTopics(User $admin, CurriculumModule $module, array $orderedAssignmentIds): void
    {
        $this->assertCan($admin, 'update', $module);
        $this->assertDraft($module->version);

        DB::transaction(function () use ($admin, $module, $orderedAssignmentIds): void {
            $version = $module->version()->lockForUpdate()->firstOrFail();
            $this->assertDraft($version);

            $assignments = $module->topicAssignments()->whereIn('id', $orderedAssignmentIds)->get()->keyBy('id');

            if ($assignments->count() !== count($orderedAssignmentIds)) {
                throw new CurriculumException('One or more topic assignments do not belong to this module.');
            }

            foreach (array_values($orderedAssignmentIds) as $index => $assignmentId) {
                $assignments->get($assignmentId)?->update(['sort_order' => $index]);
            }

            $this->audit->logUser($admin, self::LOG_NAME, 'topics_reordered', sprintf('Topics reordered for module "%s".', $module->title), $module, []);
        });
    }

    // ── Internals: cloning ───────────────────────────────────────────────

    private function cloneStructure(CurriculumVersion $source, CurriculumVersion $target, User $admin): void
    {
        $source->loadMissing('modules.topicAssignments');

        foreach ($source->modules as $module) {
            $newModule = $target->modules()->create([
                'title' => $module->title,
                'description' => $module->description,
                'sort_order' => $module->sort_order,
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]);

            foreach ($module->topicAssignments as $assignment) {
                $newModule->topicAssignments()->create([
                    'subject_topic_id' => $assignment->subject_topic_id,
                    'sort_order' => $assignment->sort_order,
                    'created_by' => $admin->id,
                ]);
            }
        }
    }

    // ── Internals: lifecycle plumbing ───────────────────────────────────

    private function transitionVersion(
        User $admin,
        CurriculumVersion $version,
        CurriculumVersionStatus $target,
        string $reason,
        string $auditEvent,
        array $extraAttributes,
    ): CurriculumVersion {
        $this->assertCan($admin, 'update', $version);

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required for this lifecycle change.']);
        }

        return DB::transaction(function () use ($admin, $version, $target, $reason, $auditEvent, $extraAttributes): CurriculumVersion {
            $locked = $this->lockedVersion($version);
            $this->assertTransition($locked, $target);

            $locked->forceFill([...$extraAttributes, 'status' => $target, 'updated_by' => $admin->id])->save();

            $this->audit->logUser($admin, self::LOG_NAME, $auditEvent, sprintf('Curriculum version %d is now %s.', $locked->version_number, $target->value), $locked, [
                'curriculum_id' => $locked->curriculum_id,
                'reason' => $reason,
            ]);

            return $locked->refresh();
        });
    }

    private function lockedVersion(CurriculumVersion $version): CurriculumVersion
    {
        return CurriculumVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
    }

    private function assertTransition(CurriculumVersion $version, CurriculumVersionStatus $target): void
    {
        if (! $version->status->canTransitionTo($target)) {
            throw new CurriculumException(sprintf(
                'A %s curriculum version cannot become %s.',
                $version->status->value,
                $target->value,
            ));
        }
    }

    private function assertDraft(CurriculumVersion $version): void
    {
        if (! $version->status->isEditable()) {
            throw new CurriculumException(sprintf(
                'A %s curriculum version is immutable — create a new version to make structural changes.',
                $version->status->value,
            ));
        }
    }

    /**
     * SRS Book 2 §4.21#5/§4.23/§4.22 publication gate: a Curriculum
     * must contain at least one Module before publication, and every
     * Module must contain at least one Topic; the owning Subject and
     * Academic Level must both be active; every assigned topic must
     * belong to the curriculum's own subject.
     */
    private function assertPublishable(CurriculumVersion $version): void
    {
        $curriculum = $version->curriculum()->with(['subject', 'academicLevel'])->firstOrFail();

        if ($curriculum->subject->status !== AcademicStatus::Active) {
            throw new CurriculumException('This curriculum\'s subject is not active — publication is blocked.');
        }

        if ($curriculum->academicLevel->status !== AcademicStatus::Active) {
            throw new CurriculumException('This curriculum\'s academic level is not active — publication is blocked.');
        }

        $modules = $version->modules()->with('topicAssignments.topic')->get();

        if ($modules->isEmpty()) {
            throw new CurriculumException('A curriculum version must contain at least one module before it can be published.');
        }

        foreach ($modules as $module) {
            if ($module->topicAssignments->isEmpty()) {
                throw new CurriculumException(sprintf('Module "%s" has no topics — every module must contain at least one topic before publication.', $module->title));
            }

            foreach ($module->topicAssignments as $assignment) {
                if ($assignment->topic === null || $assignment->topic->subject_id !== $curriculum->subject_id) {
                    throw new CurriculumException(sprintf('Module "%s" references a topic from another subject — publication is blocked.', $module->title));
                }
            }
        }
    }

    // ── Internals: shared validation ─────────────────────────────────────

    private function activeSubject(string $subjectId): Subject
    {
        $subject = Subject::query()->find($subjectId);

        if ($subject === null) {
            throw ValidationException::withMessages(['subject_id' => 'Select a valid subject.']);
        }

        if ($subject->status !== AcademicStatus::Active) {
            throw new CurriculumException('Only an active subject may own a curriculum.');
        }

        return $subject;
    }

    private function activeAcademicLevel(string $academicLevelId): AcademicLevel
    {
        $level = AcademicLevel::query()->find($academicLevelId);

        if ($level === null) {
            throw ValidationException::withMessages(['academic_level_id' => 'Select a valid academic level.']);
        }

        if ($level->status !== AcademicStatus::Active) {
            throw new CurriculumException('Only an active academic level may own a curriculum.');
        }

        return $level;
    }

    private function assertUniqueSlug(string $subjectId, string $academicLevelId, string $slug, ?string $ignoreCurriculumId = null): void
    {
        $exists = Curriculum::query()
            ->where('subject_id', $subjectId)
            ->where('academic_level_id', $academicLevelId)
            ->where('slug', $slug)
            ->when($ignoreCurriculumId !== null, fn ($q) => $q->whereKeyNot($ignoreCurriculumId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['slug' => 'A curriculum with this slug already exists for the selected subject and academic level.']);
        }
    }

    private function assertCan(User $admin, string $ability, mixed $subject): void
    {
        if (! $admin->can($ability, $subject)) {
            throw new AuthorizationException;
        }
    }
}
