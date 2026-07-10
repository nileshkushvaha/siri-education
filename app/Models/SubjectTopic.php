<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AcademicStatus;
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
 * A teachable part of a Subject (Mathematics → Algebra), optionally
 * nested one level (Algebra → Linear Equations) via parent_id. Topics
 * drive instructor matching (instructor_subject_topics) — never
 * pricing: student_lesson_prices stays keyed on subject/level.
 */
class SubjectTopic extends Model
{
    use HasFactory, HasUuids, LogsActivity, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    /** Matches the DB column defaults so a fresh instance is correct before save(). */
    protected $attributes = [
        'status' => 'active',
        'display_order' => 0,
    ];

    protected $fillable = [
        'subject_id',
        'parent_id',
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
        static::creating(function (SubjectTopic $topic): void {
            $topic->created_by ??= auth()->id();
            $topic->updated_by ??= auth()->id();
        });

        static::updating(function (SubjectTopic $topic): void {
            $topic->updated_by = auth()->id();
        });
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('display_order');
    }

    public function instructorCoverage(): HasMany
    {
        return $this->hasMany(InstructorSubjectTopic::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Active topics only — the pool visible to booking and marketplace. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', AcademicStatus::Active);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['subject_id', 'parent_id', 'name', 'slug', 'status', 'display_order'])
            ->useLogName('subjects')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
