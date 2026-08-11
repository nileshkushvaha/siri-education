<?php

declare(strict_types=1);

namespace App\Models;

use App\Curriculum\Enums\CurriculumVersionStatus;
use App\Support\Concerns\PreventsHardDeletion;
use Database\Factories\CurriculumVersionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A single point-in-time state of a Curriculum (SRS Book 2 §5.8) —
 * this is the object a future Learning Plan will reference by stable
 * id, not the parent Curriculum. `version_number` is sequential per
 * curriculum, unique together, never reused. Modules/topics belong to
 * exactly one version row; "editing published content" always means
 * creating a new Draft version via CurriculumService::createNewVersion(),
 * never mutating this row's structure once Published (enforced in the
 * service, not the DB — see isEditable()/status transitions).
 * Soft-deletable (a Draft may be discarded) but never hard-deletable
 * (PreventsHardDeletion) — a Published/Archived/Retired row is a
 * permanent historical record.
 */
class CurriculumVersion extends Model
{
    /** @use HasFactory<CurriculumVersionFactory> */
    use HasFactory, HasUuids, LogsActivity, PreventsHardDeletion, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $attributes = [
        'status' => 'draft',
        'version_number' => 1,
    ];

    protected $fillable = [
        'curriculum_id',
        'version_number',
        'status',
        'notes',
        'published_at',
        'archived_at',
        'retired_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => CurriculumVersionStatus::class,
            'version_number' => 'integer',
            'published_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'retired_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CurriculumVersion $version): void {
            $version->created_by ??= auth()->id();
            $version->updated_by ??= auth()->id();
        });

        static::updating(function (CurriculumVersion $version): void {
            $version->updated_by = auth()->id();
        });
    }

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    public function modules(): HasMany
    {
        return $this->hasMany(CurriculumModule::class)->orderBy('sort_order');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeStatus(Builder $query, CurriculumVersionStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['curriculum_id', 'version_number', 'status', 'notes'])
            ->useLogName('curriculum_versions')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
