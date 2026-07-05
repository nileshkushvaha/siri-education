<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Standalone reference data (Beginner/Intermediate/Advanced/Expert) —
 * optional per the Phase 1 spec. Not yet attached to Subject or
 * TeacherSubject; wiring proficiency into instructor/subject
 * relationships is a later phase.
 */
class SkillLevel extends Model
{
    use HasUuids, LogsActivity, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    /** Matches the DB column default so a fresh instance is correct before save(), not just after a re-fetch. */
    protected $attributes = [
        'is_active' => true,
        'display_order' => 0,
    ];

    protected $fillable = [
        'name',
        'slug',
        'display_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'display_order' => 'integer',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'slug', 'display_order', 'is_active'])
            ->useLogName('skill_levels')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
