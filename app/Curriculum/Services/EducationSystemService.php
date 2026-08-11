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

        $attributes = collect($data)->only(['name', 'slug', 'code', 'description', 'status', 'display_order'])->all();

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

    private function assertCan(User $admin, string $ability, mixed $subject): void
    {
        if (! $admin->can($ability, $subject)) {
            throw new AuthorizationException;
        }
    }
}
