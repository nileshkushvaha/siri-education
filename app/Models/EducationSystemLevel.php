<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Concerns\PreventsHardDeletion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Phase 3.1 — the exact, student-selectable level within an Education
 * System (CBSE "Class 10", US "Grade 10", UK "Year 10"). This is what
 * a student actually picks in the country-aware booking flow; it is
 * NOT the same concept as AcademicLevel (the broad internal band —
 * Middle School/Secondary/Higher Secondary — used for Curriculum
 * ownership and reporting). Selecting an EducationSystemLevel implies
 * its academic_level_id; students never choose AcademicLevel directly.
 *
 * Deliberately a standalone table rather than a reuse of the existing
 * EducationSystemAcademicLevel pivot: that pivot is unique per
 * (education_system_id, academic_level_id) — one row per broad band —
 * whereas several distinct levels here (Class 6..Class 8) legitimately
 * share one AcademicLevel band. No FK is added to academic_levels
 * itself (see docs/architecture/domain-registry.md).
 *
 * normalized_grade bridges back onto the existing universal 1-12 int
 * the booking/matching engine already uses (TeacherSubject::grade_from/
 * grade_to) — nullable to allow future non-numeric levels (Undergraduate,
 * Foundation, ...) without forcing a fake int. A level with a null
 * normalized_grade is currently unsupported for Demo booking (see
 * DemoAcademicContextResolver) — no invented subject-only fallback.
 *
 * Historical safety: this row's id is snapshotted onto
 * BookingAcademicContext, so PreventsHardDeletion blocks forceDelete();
 * normal delete()/restore() (soft delete, e.g. deactivating a level)
 * remains unaffected.
 */
class EducationSystemLevel extends Model
{
    use HasUuids, LogsActivity, PreventsHardDeletion, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    /** Matches the DB column defaults so a fresh instance is correct before save(), not just after a re-fetch. */
    protected $attributes = [
        'display_order' => 0,
        'is_active' => true,
    ];

    protected $fillable = [
        'education_system_id',
        'academic_level_id',
        'value',
        'display_label',
        'normalized_grade',
        'display_order',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'normalized_grade' => 'integer',
            'display_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (EducationSystemLevel $level): void {
            $level->created_by ??= auth()->id();
            $level->updated_by ??= auth()->id();
        });

        static::updating(function (EducationSystemLevel $level): void {
            $level->updated_by = auth()->id();
        });
    }

    public function educationSystem(): BelongsTo
    {
        return $this->belongsTo(EducationSystem::class);
    }

    public function academicLevel(): BelongsTo
    {
        return $this->belongsTo(AcademicLevel::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Active levels only — the pool selectable by a student. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['education_system_id', 'academic_level_id', 'value', 'display_label', 'normalized_grade', 'display_order', 'is_active'])
            ->useLogName('education_system_levels')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
