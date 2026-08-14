<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Concerns\PreventsHardDeletion;
use App\Support\Concerns\PreventsUpdates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 4C.2 — "this completed lesson consumed one unit from this
 * entitlement."
 *
 * Write-once by construction: PreventsUpdates blocks every post-create
 * write and PreventsHardDeletion blocks removal, because a consumption
 * is evidence. A correction workflow, if one is ever needed, must be
 * designed deliberately rather than by editing history.
 *
 * The DB's UNIQUE(lesson_id) is the real idempotency guarantee — this
 * model's guards protect the row after it exists, the index prevents a
 * second one from ever being written.
 */
class StudentPackageEntitlementConsumption extends Model
{
    use HasUuids, PreventsHardDeletion, PreventsUpdates;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'entitlement_id',
        'lesson_id',
        'student_id',
        'instructor_id',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'consumed_at' => 'immutable_datetime',
        ];
    }

    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(StudentPackageEntitlement::class, 'entitlement_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function scopeForLesson(Builder $query, string $lessonId): Builder
    {
        return $query->where('lesson_id', $lessonId);
    }
}
