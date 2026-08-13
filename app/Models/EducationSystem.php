<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AcademicStatus;
use App\Support\Concerns\PreventsHardDeletion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A configurable academic framework/program (e.g. CBSE, ICSE, IB,
 * GCSE, SAT, AP, "US Common Academic System") — administrator-managed
 * reference data, deliberately generic: no CbseBoard/IcseBoard/
 * UkCurriculum model and no hardcoded board enum. Applicability to
 * Country/AcademicLevel/Curriculum is expressed entirely through the
 * mapping models below (CountryEducationSystem,
 * EducationSystemAcademicLevel, CurriculumEducationSystem) because
 * the relationships are many-to-many in every direction — never a
 * direct FK on Country/AcademicLevel/Curriculum. See
 * AcademicContextResolver and docs/architecture/domain-registry.md.
 *
 * Primarily configuration/reference data, not a versioned/lifecycle
 * entity — CurriculumVersion remains the only Draft/Published/
 * Archived/Retired workflow in this domain. Status reuses the shared
 * AcademicStatus enum (Active/Inactive/Archived), matching Subject/
 * AcademicLevel/AcademicCategory rather than inventing a new workflow.
 *
 * Historical safety (Phase 1.1): despite being reference/configuration
 * data, this row's id will later be snapshotted by instructor academic
 * eligibility, booking academic context, Learning Plans and reporting —
 * the same class of "id must remain stable forever" concern that
 * Curriculum/CurriculumVersion already carry. PreventsHardDeletion
 * blocks forceDelete() (an auto-generated ForceDelete:EducationSystem
 * permission does not bypass this); normal delete()/restore() (soft
 * delete, e.g. deactivating/archiving a system) remains unaffected.
 */
class EducationSystem extends Model
{
    use HasUuids, LogsActivity, PreventsHardDeletion, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    /** Matches the DB column defaults so a fresh instance is correct before save(), not just after a re-fetch. */
    protected $attributes = [
        'status' => 'active',
        'display_order' => 0,
    ];

    protected $fillable = [
        'name',
        'slug',
        'code',
        'description',
        'status',
        'display_order',
        'level_term_singular',
        'level_term_plural',
        'created_by',
        'updated_by',
    ];

    /** Generic fallback when an admin hasn't configured this system's terminology yet. */
    private const string DEFAULT_LEVEL_TERM_SINGULAR = 'Level';

    private const string DEFAULT_LEVEL_TERM_PLURAL = 'Levels';

    protected function casts(): array
    {
        return [
            'status' => AcademicStatus::class,
            'display_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (EducationSystem $system): void {
            $system->created_by ??= auth()->id();
            $system->updated_by ??= auth()->id();
        });

        static::updating(function (EducationSystem $system): void {
            $system->updated_by = auth()->id();
        });
    }

    public function countryMappings(): HasMany
    {
        return $this->hasMany(CountryEducationSystem::class);
    }

    public function academicLevelMappings(): HasMany
    {
        return $this->hasMany(EducationSystemAcademicLevel::class);
    }

    /** The exact, student-selectable levels under this system (Class 6..12, Grade 6..12, Year 6..12, ...). */
    public function levels(): HasMany
    {
        return $this->hasMany(EducationSystemLevel::class);
    }

    public function curriculumMappings(): HasMany
    {
        return $this->hasMany(CurriculumEducationSystem::class);
    }

    /** Admin-approved Instructor eligibility rows against this system — see InstructorCurriculumEligibility. */
    public function instructorEligibilities(): HasMany
    {
        return $this->hasMany(InstructorCurriculumEligibility::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Active systems only — the pool eligible for new mappings/resolution. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', AcademicStatus::Active);
    }

    /** The term this system uses for a student-selectable level (e.g. "Class", "Grade", "Year"); "Level" when unconfigured. */
    public function levelTermSingular(): string
    {
        return filled($this->level_term_singular) ? $this->level_term_singular : self::DEFAULT_LEVEL_TERM_SINGULAR;
    }

    public function levelTermPlural(): string
    {
        return filled($this->level_term_plural) ? $this->level_term_plural : self::DEFAULT_LEVEL_TERM_PLURAL;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'slug', 'code', 'description', 'status', 'display_order', 'level_term_singular', 'level_term_plural'])
            ->useLogName('academic_systems')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
