<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AcademicStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Academic master data only — this is NOT yet wired into the booking
 * flow. TeacherSubject.subject stays a free-text string, and booking
 * DTOs/validation are untouched; that migration is a deliberate later
 * phase, not part of the Phase 1 foundation.
 */
class Subject extends Model
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
        'academic_category_id',
        'name',
        'slug',
        'description',
        'status',
        'display_order',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => AcademicStatus::class,
            'display_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Subject $subject): void {
            $subject->created_by = auth()->id();
            $subject->updated_by = auth()->id();
        });

        static::updating(function (Subject $subject): void {
            $subject->updated_by = auth()->id();
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AcademicCategory::class, 'academic_category_id');
    }

    public function countries(): BelongsToMany
    {
        return $this->belongsToMany(Country::class, 'subject_country');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Active subjects only — the pool eligible for new teacher assignments. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', AcademicStatus::Active);
    }

    /** Same as active() today; kept as a distinct name so callers express intent. */
    public function scopeAvailableForAssignment(Builder $query): Builder
    {
        return $query->active();
    }

    /** No rows in subject_country = available everywhere. */
    public function isAvailableInCountry(Country $country): bool
    {
        if ($this->countries()->count() === 0) {
            return true;
        }

        return $this->countries()->whereKey($country->id)->exists();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['academic_category_id', 'name', 'slug', 'description', 'status', 'display_order'])
            ->useLogName('subjects')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
