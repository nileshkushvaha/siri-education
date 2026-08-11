<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AcademicStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Named grade bands (Primary, Middle School, High School, Undergraduate,
 * ...) used for marketplace search/filtering. Deliberately NOT named
 * EducationLevel: App\Enums\EducationLevel already exists and means
 * something different — an instructor's own academic credential type
 * (Bachelor/Master/Doctorate/...), used by UserEducation. Reusing that
 * name here would silently conflate two unrelated concepts.
 *
 * min_grade/max_grade bridge to the existing raw grade int (1-12)
 * already used throughout the booking flow (TeacherSubject::grade_from/
 * grade_to, AssignmentCriteriaData::$grade) — this model does not
 * replace that; it's an admin-manageable label over the same numbers.
 */
class AcademicLevel extends Model
{
    use HasUuids, LogsActivity, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    /** Matches the DB column default so a fresh instance is correct before save(), not just after a re-fetch. */
    protected $attributes = [
        'status' => 'active',
        'display_order' => 0,
    ];

    protected $fillable = [
        'name',
        'slug',
        'description',
        'min_grade',
        'max_grade',
        'country_id',
        'status',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'status' => AcademicStatus::class,
            'min_grade' => 'integer',
            'max_grade' => 'integer',
            'display_order' => 'integer',
        ];
    }

    /**
     * The education system this level belongs to (UK "Year 10", US
     * "Grade 9"); null = global. A full education_systems module is
     * deliberately deferred — see phase-12.5 architecture doc.
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /** Levels usable in the given country: its own system's plus global ones. */
    public function scopeForCountry(Builder $query, ?int $countryId): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('country_id')
            ->when($countryId !== null, fn (Builder $inner) => $inner->orWhere('country_id', $countryId)));
    }

    /** Active levels only — the pool eligible for new teacher/subject assignments. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', AcademicStatus::Active);
    }

    public function scopeAvailableForAssignment(Builder $query): Builder
    {
        return $query->active();
    }

    public function coversGrade(int $grade): bool
    {
        if ($this->min_grade === null && $this->max_grade === null) {
            return false;
        }

        return $grade >= ($this->min_grade ?? 0) && $grade <= ($this->max_grade ?? 12);
    }

    public function studentProfiles(): HasMany
    {
        return $this->hasMany(UserProfile::class, 'student_academic_level_id');
    }

    public function studentLearningGoals(): HasMany
    {
        return $this->hasMany(StudentLearningGoal::class);
    }

    public function studentLearningPlans(): HasMany
    {
        return $this->hasMany(StudentLearningPlan::class);
    }

    /** EducationSystems this level is mapped into (see EducationSystemAcademicLevel). */
    public function educationSystemMappings(): HasMany
    {
        return $this->hasMany(EducationSystemAcademicLevel::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'slug', 'description', 'min_grade', 'max_grade', 'status', 'display_order'])
            ->useLogName('academic_levels')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
