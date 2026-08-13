<?php

declare(strict_types=1);

namespace App\Curriculum\Services;

use App\Curriculum\Exceptions\AcademicContextException;
use App\Enums\AcademicStatus;
use App\Models\AcademicLevel;
use App\Models\Country;
use App\Models\CountryEducationSystem;
use App\Models\Curriculum;
use App\Models\CurriculumEducationSystem;
use App\Models\EducationSystem;
use App\Models\EducationSystemAcademicLevel;
use App\Models\EducationSystemLevel;
use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The single authoritative writer of EducationSystem configuration and
 * its Country/AcademicLevel/Curriculum mappings. Filament calls this
 * service exclusively — mapping rows are never attached/detached
 * directly from a Resource/RelationManager, so duplicate-mapping
 * prevention and audit logging cannot be bypassed (mirrors
 * CurriculumService's role for the Curriculum domain).
 */
final class EducationSystemService
{
    /**
     * Mapping events are logged under a dedicated log_name rather than
     * Phase 0A's 'curricula' — they describe configuration of a
     * distinct entity (EducationSystem and its Country/Level/Curriculum
     * applicability), not curriculum content itself, and administrators
     * auditing "who changed which board is offered where" benefit from
     * being able to filter that activity independently of curriculum
     * structural edits.
     */
    private const LOG_NAME = 'academic_systems';

    public function __construct(
        private readonly AuditTrailService $audit,
    ) {}

    // ── EducationSystem ──────────────────────────────────────────────────

    /**
     * @param  array{name: string, slug?: string|null, code?: string|null, description?: string|null, status?: string|null, display_order?: int|null}  $data
     */
    public function createEducationSystem(User $admin, array $data): EducationSystem
    {
        $this->assertCan($admin, 'create', EducationSystem::class);

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'A name is required.']);
        }

        $slug = trim((string) ($data['slug'] ?? '')) ?: Str::slug($name);

        return DB::transaction(function () use ($admin, $name, $slug, $data): EducationSystem {
            $this->assertUniqueSlug($slug);

            $system = EducationSystem::query()->create([
                'name' => $name,
                'slug' => $slug,
                'code' => $data['code'] ?? null,
                'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? AcademicStatus::Active->value,
                'display_order' => $data['display_order'] ?? 0,
                'level_term_singular' => $data['level_term_singular'] ?? null,
                'level_term_plural' => $data['level_term_plural'] ?? null,
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]);

            $this->audit->logUser($admin, self::LOG_NAME, 'education_system_created', sprintf('Education system "%s" created.', $system->name), $system, []);

            return $system->refresh();
        });
    }

    /**
     * @param  array{name?: string, slug?: string, code?: string|null, description?: string|null, status?: string, display_order?: int}  $data
     */
    public function updateEducationSystem(User $admin, EducationSystem $system, array $data): EducationSystem
    {
        $this->assertCan($admin, 'update', $system);

        $attributes = collect($data)->only(['name', 'slug', 'code', 'description', 'status', 'display_order', 'level_term_singular', 'level_term_plural'])->all();

        if (array_key_exists('name', $attributes) && trim((string) $attributes['name']) === '') {
            throw ValidationException::withMessages(['name' => 'A name is required.']);
        }

        return DB::transaction(function () use ($admin, $system, $attributes): EducationSystem {
            if (array_key_exists('slug', $attributes)) {
                $this->assertUniqueSlug((string) $attributes['slug'], $system->id);
            }

            $system->fill([...$attributes, 'updated_by' => $admin->id])->save();

            $this->audit->logUser($admin, self::LOG_NAME, 'education_system_updated', sprintf('Education system "%s" updated.', $system->name), $system, [
                'changed_fields' => array_keys($attributes),
            ]);

            return $system->refresh();
        });
    }

    // ── Country mapping ──────────────────────────────────────────────────

    public function mapToCountry(User $admin, EducationSystem $system, Country $country, bool $isActive = true, ?int $displayOrder = null): CountryEducationSystem
    {
        $this->assertCan($admin, 'update', $system);

        return DB::transaction(function () use ($admin, $system, $country, $isActive, $displayOrder): CountryEducationSystem {
            if (CountryEducationSystem::query()->where('country_id', $country->id)->where('education_system_id', $system->id)->exists()) {
                throw new AcademicContextException(sprintf('"%s" is already mapped to %s.', $system->name, $country->name));
            }

            $mapping = CountryEducationSystem::query()->create([
                'country_id' => $country->id,
                'education_system_id' => $system->id,
                'is_active' => $isActive,
                'display_order' => $displayOrder ?? 0,
                'created_by' => $admin->id,
            ]);

            $this->audit->logUser($admin, self::LOG_NAME, 'country_mapping_added', sprintf('Education system "%s" mapped to country "%s".', $system->name, $country->name), $mapping, [
                'education_system_id' => $system->id,
                'country_id' => $country->id,
            ]);

            return $mapping;
        });
    }

    public function unmapFromCountry(User $admin, CountryEducationSystem $mapping): void
    {
        $this->assertCan($admin, 'update', $mapping->educationSystem);

        DB::transaction(function () use ($admin, $mapping): void {
            $systemName = $mapping->educationSystem?->name ?? (string) $mapping->education_system_id;
            $countryName = $mapping->country?->name ?? (string) $mapping->country_id;
            $educationSystemId = $mapping->education_system_id;
            $countryId = $mapping->country_id;

            $mapping->delete();

            $this->audit->logUser($admin, self::LOG_NAME, 'country_mapping_removed', sprintf('Education system "%s" unmapped from country "%s".', $systemName, $countryName), null, [
                'education_system_id' => $educationSystemId,
                'country_id' => $countryId,
            ]);
        });
    }

    // ── Academic Level mapping ───────────────────────────────────────────

    public function mapToAcademicLevel(User $admin, EducationSystem $system, AcademicLevel $level, bool $isActive = true, ?int $displayOrder = null): EducationSystemAcademicLevel
    {
        $this->assertCan($admin, 'update', $system);

        return DB::transaction(function () use ($admin, $system, $level, $isActive, $displayOrder): EducationSystemAcademicLevel {
            if (EducationSystemAcademicLevel::query()->where('education_system_id', $system->id)->where('academic_level_id', $level->id)->exists()) {
                throw new AcademicContextException(sprintf('"%s" is already mapped to "%s".', $level->name, $system->name));
            }

            $mapping = EducationSystemAcademicLevel::query()->create([
                'education_system_id' => $system->id,
                'academic_level_id' => $level->id,
                'is_active' => $isActive,
                'display_order' => $displayOrder ?? 0,
                'created_by' => $admin->id,
            ]);

            $this->audit->logUser($admin, self::LOG_NAME, 'level_mapping_added', sprintf('Academic level "%s" mapped to education system "%s".', $level->name, $system->name), $mapping, [
                'education_system_id' => $system->id,
                'academic_level_id' => $level->id,
            ]);

            return $mapping;
        });
    }

    public function unmapFromAcademicLevel(User $admin, EducationSystemAcademicLevel $mapping): void
    {
        $this->assertCan($admin, 'update', $mapping->educationSystem);

        DB::transaction(function () use ($admin, $mapping): void {
            $systemName = $mapping->educationSystem?->name ?? (string) $mapping->education_system_id;
            $levelName = $mapping->academicLevel?->name ?? (string) $mapping->academic_level_id;
            $educationSystemId = $mapping->education_system_id;
            $academicLevelId = $mapping->academic_level_id;

            $mapping->delete();

            $this->audit->logUser($admin, self::LOG_NAME, 'level_mapping_removed', sprintf('Academic level "%s" unmapped from education system "%s".', $levelName, $systemName), null, [
                'education_system_id' => $educationSystemId,
                'academic_level_id' => $academicLevelId,
            ]);
        });
    }

    // ── EducationSystemLevel (Phase 3.1 §18) ─────────────────────────────
    //
    // The exact, student-selectable level within a system (Class 10 /
    // Grade 10 / Year 10) — distinct from the broad AcademicLevel
    // mapping above. Mutations always go through this service, never a
    // raw Filament attach/detach, mirroring every other mapping in this
    // class.

    /**
     * @param  array{academic_level_id: string, value: string, display_label: string, normalized_grade?: int|null, is_active?: bool, display_order?: int}  $data
     */
    public function addLevel(User $admin, EducationSystem $system, array $data): EducationSystemLevel
    {
        $this->assertCan($admin, 'update', $system);

        $level = AcademicLevel::query()->find($data['academic_level_id'] ?? null);

        if ($level === null) {
            throw ValidationException::withMessages(['academic_level_id' => 'An academic level is required.']);
        }

        $value = trim((string) ($data['value'] ?? ''));
        $displayLabel = trim((string) ($data['display_label'] ?? ''));

        if ($value === '' || $displayLabel === '') {
            throw ValidationException::withMessages(['value' => 'A value and display label are required.']);
        }

        return DB::transaction(function () use ($admin, $system, $level, $value, $displayLabel, $data): EducationSystemLevel {
            $this->assertUniqueLevelValue($system, $value);

            $educationSystemLevel = EducationSystemLevel::query()->create([
                'education_system_id' => $system->id,
                'academic_level_id' => $level->id,
                'value' => $value,
                'display_label' => $displayLabel,
                'normalized_grade' => $data['normalized_grade'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'display_order' => $data['display_order'] ?? 0,
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]);

            $this->audit->logUser($admin, self::LOG_NAME, 'level_added', sprintf('Level "%s" added to education system "%s".', $displayLabel, $system->name), $educationSystemLevel, [
                'education_system_id' => $system->id,
                'academic_level_id' => $level->id,
            ]);

            return $educationSystemLevel;
        });
    }

    /**
     * @param  array{academic_level_id?: string, value?: string, display_label?: string, normalized_grade?: int|null, is_active?: bool, display_order?: int}  $data
     */
    public function updateLevel(User $admin, EducationSystemLevel $educationSystemLevel, array $data): EducationSystemLevel
    {
        $this->assertCan($admin, 'update', $educationSystemLevel->educationSystem);

        $attributes = collect($data)->only(['academic_level_id', 'value', 'display_label', 'normalized_grade', 'is_active', 'display_order'])->all();

        if (array_key_exists('value', $attributes) && trim((string) $attributes['value']) === '') {
            throw ValidationException::withMessages(['value' => 'A value is required.']);
        }

        if (array_key_exists('display_label', $attributes) && trim((string) $attributes['display_label']) === '') {
            throw ValidationException::withMessages(['display_label' => 'A display label is required.']);
        }

        return DB::transaction(function () use ($admin, $educationSystemLevel, $attributes): EducationSystemLevel {
            if (array_key_exists('value', $attributes)) {
                $this->assertUniqueLevelValue($educationSystemLevel->educationSystem, (string) $attributes['value'], $educationSystemLevel->id);
            }

            if (array_key_exists('academic_level_id', $attributes) && AcademicLevel::query()->find($attributes['academic_level_id']) === null) {
                throw ValidationException::withMessages(['academic_level_id' => 'An academic level is required.']);
            }

            $educationSystemLevel->fill([...$attributes, 'updated_by' => $admin->id])->save();

            $this->audit->logUser($admin, self::LOG_NAME, 'level_updated', sprintf('Level "%s" updated on education system "%s".', $educationSystemLevel->display_label, $educationSystemLevel->educationSystem->name), $educationSystemLevel, [
                'changed_fields' => array_keys($attributes),
            ]);

            return $educationSystemLevel->refresh();
        });
    }

    public function removeLevel(User $admin, EducationSystemLevel $educationSystemLevel): void
    {
        $this->assertCan($admin, 'update', $educationSystemLevel->educationSystem);

        DB::transaction(function () use ($admin, $educationSystemLevel): void {
            $systemName = $educationSystemLevel->educationSystem?->name ?? (string) $educationSystemLevel->education_system_id;
            $displayLabel = $educationSystemLevel->display_label;
            $educationSystemId = $educationSystemLevel->education_system_id;

            // Soft delete — booking history (BookingAcademicContext)
            // denormalizes its own display values, so an existing
            // Booking's historical display is never affected by
            // removing/deactivating a level later (§40).
            $educationSystemLevel->delete();

            $this->audit->logUser($admin, self::LOG_NAME, 'level_removed', sprintf('Level "%s" removed from education system "%s".', $displayLabel, $systemName), null, [
                'education_system_id' => $educationSystemId,
            ]);
        });
    }

    // ── Curriculum mapping ───────────────────────────────────────────────

    public function mapToCurriculum(User $admin, EducationSystem $system, Curriculum $curriculum): CurriculumEducationSystem
    {
        $this->assertCan($admin, 'update', $system);

        return DB::transaction(function () use ($admin, $system, $curriculum): CurriculumEducationSystem {
            if (CurriculumEducationSystem::query()->where('curriculum_id', $curriculum->id)->where('education_system_id', $system->id)->exists()) {
                throw new AcademicContextException(sprintf('"%s" is already mapped to education system "%s".', $curriculum->name, $system->name));
            }

            $mapping = CurriculumEducationSystem::query()->create([
                'curriculum_id' => $curriculum->id,
                'education_system_id' => $system->id,
                'created_by' => $admin->id,
            ]);

            $this->audit->logUser($admin, self::LOG_NAME, 'curriculum_mapping_added', sprintf('Curriculum "%s" mapped to education system "%s".', $curriculum->name, $system->name), $mapping, [
                'education_system_id' => $system->id,
                'curriculum_id' => $curriculum->id,
            ]);

            return $mapping;
        });
    }

    public function unmapFromCurriculum(User $admin, CurriculumEducationSystem $mapping): void
    {
        $this->assertCan($admin, 'update', $mapping->educationSystem);

        DB::transaction(function () use ($admin, $mapping): void {
            $systemName = $mapping->educationSystem?->name ?? (string) $mapping->education_system_id;
            $curriculumName = $mapping->curriculum?->name ?? (string) $mapping->curriculum_id;
            $educationSystemId = $mapping->education_system_id;
            $curriculumId = $mapping->curriculum_id;

            $mapping->delete();

            $this->audit->logUser($admin, self::LOG_NAME, 'curriculum_mapping_removed', sprintf('Curriculum "%s" unmapped from education system "%s".', $curriculumName, $systemName), null, [
                'education_system_id' => $educationSystemId,
                'curriculum_id' => $curriculumId,
            ]);
        });
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function assertUniqueSlug(string $slug, ?string $ignoreId = null): void
    {
        $exists = EducationSystem::query()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['slug' => 'An education system with this slug already exists.']);
        }
    }

    private function assertUniqueLevelValue(EducationSystem $system, string $value, ?string $ignoreId = null): void
    {
        $exists = EducationSystemLevel::query()
            ->where('education_system_id', $system->id)
            ->where('value', $value)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw new AcademicContextException(sprintf('"%s" already has a level with value "%s".', $system->name, $value));
        }
    }

    private function assertCan(User $admin, string $ability, mixed $subject): void
    {
        if (! $admin->can($ability, $subject)) {
            throw new AuthorizationException;
        }
    }
}
