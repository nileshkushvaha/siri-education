<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CurriculumModuleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A logical grouping of SubjectTopic rows within one CurriculumVersion
 * (SRS Book 2 §4.10/§5.9). Belongs to exactly one version — never
 * shared/mutated across versions. Structural mutation (create/update/
 * delete/reorder) is only permitted through CurriculumService while
 * the owning version is Draft; no soft-delete/hard-delete guard is
 * needed here because the parent version itself becomes immutable
 * (enforced in the service) the moment it leaves Draft.
 */
class CurriculumModule extends Model
{
    /** @use HasFactory<CurriculumModuleFactory> */
    use HasFactory, HasUuids, LogsActivity;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $attributes = [
        'sort_order' => 0,
    ];

    protected $fillable = [
        'curriculum_version_id',
        'title',
        'description',
        'sort_order',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CurriculumModule $module): void {
            $module->created_by ??= auth()->id();
            $module->updated_by ??= auth()->id();
        });

        static::updating(function (CurriculumModule $module): void {
            $module->updated_by = auth()->id();
        });
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(CurriculumVersion::class, 'curriculum_version_id');
    }

    public function topicAssignments(): HasMany
    {
        return $this->hasMany(CurriculumModuleTopic::class)->orderBy('sort_order');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['curriculum_version_id', 'title', 'description', 'sort_order'])
            ->useLogName('curriculum_modules')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
