<?php

declare(strict_types=1);

namespace App\Models;

use App\Curriculum\Enums\CurriculumVersionStatus;
use App\Support\Concerns\PreventsHardDeletion;
use Database\Factories\CurriculumFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Curriculum identity only (SRS Book 2 §4.9) — scoped to one Subject
 * and one AcademicLevel. Never carries lifecycle/versioned educational
 * content itself; every version of "what this curriculum teaches"
 * lives in a child CurriculumVersion row (see that model's docblock
 * for why). Deliberately carries no education_system_id/board_id yet
 * — SRS §4.25 defers boards to a later phase; a nullable FK can be
 * added here later without redesigning the domain.
 */
class Curriculum extends Model
{
    /** @use HasFactory<CurriculumFactory> */
    use HasFactory, HasUuids, LogsActivity, PreventsHardDeletion, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'subject_id',
        'academic_level_id',
        'name',
        'slug',
        'description',
        'created_by',
        'updated_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (Curriculum $curriculum): void {
            $curriculum->created_by ??= auth()->id();
            $curriculum->updated_by ??= auth()->id();
        });

        static::updating(function (Curriculum $curriculum): void {
            $curriculum->updated_by = auth()->id();
        });
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function academicLevel(): BelongsTo
    {
        return $this->belongsTo(AcademicLevel::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CurriculumVersion::class)->orderByDesc('version_number');
    }

    /**
     * EducationSystems this curriculum is mapped into (see
     * CurriculumEducationSystem). Zero rows = globally applicable /
     * system-neutral curriculum (legacy-compatibility rule — see
     * AcademicContextResolver).
     */
    public function educationSystemMappings(): HasMany
    {
        return $this->hasMany(CurriculumEducationSystem::class);
    }

    /** Admin-approved Instructor eligibility rows against this curriculum — see InstructorCurriculumEligibility. */
    public function instructorEligibilities(): HasMany
    {
        return $this->hasMany(InstructorCurriculumEligibility::class);
    }

    /**
     * Same "zero mappings = globally applicable" rule
     * AcademicContextResolver::eligibleCurriculaQuery()/
     * assertCurriculumMatchesContext() apply — extracted here so
     * InstructorAcademicEligibilityService can reuse the single
     * authoritative predicate instead of re-deriving it.
     */
    public function appliesToEducationSystem(EducationSystem $system): bool
    {
        return $this->educationSystemMappings()->doesntExist()
            || $this->educationSystemMappings()->where('education_system_id', $system->id)->exists();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Highest version_number currently in Published status — "the" curriculum students/instructors would currently follow. */
    public function latestPublishedVersion(): ?CurriculumVersion
    {
        return $this->versions()
            ->where('status', CurriculumVersionStatus::Published)
            ->orderByDesc('version_number')
            ->first();
    }

    public function latestVersion(): ?CurriculumVersion
    {
        return $this->versions()->orderByDesc('version_number')->first();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['subject_id', 'academic_level_id', 'name', 'slug', 'description'])
            ->useLogName('curricula')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
