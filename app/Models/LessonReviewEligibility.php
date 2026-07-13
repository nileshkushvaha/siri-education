<?php

declare(strict_types=1);

namespace App\Models;

use App\Reviews\Enums\LessonReviewEligibilityMode;
use App\Reviews\Enums\LessonReviewEligibilityStatus;
use App\Reviews\Enums\ReviewableLessonType;
use Database\Factories\LessonReviewEligibilityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One student review-eligibility window per lesson (unique
 * lesson_id + student_id). Written exclusively by
 * ReviewEligibilityService and its actions — status transitions
 * happen on this same row (never a new row, never a delete), so
 * `history` always holds the complete prior-state trail.
 */
class LessonReviewEligibility extends Model
{
    /** @use HasFactory<LessonReviewEligibilityFactory> */
    use HasFactory, HasUuids, LogsActivity;

    protected $fillable = [
        'lesson_id',
        'booking_id',
        'student_id',
        'instructor_id',
        'lesson_type',
        'eligibility_mode',
        'status',
        'opens_at',
        'expires_at',
        'outcome_snapshot',
        'source_outcome_version',
        'used_at',
        'revoked_at',
        'revoked_reason',
        'version',
        'history',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'lesson_type' => ReviewableLessonType::class,
            'eligibility_mode' => LessonReviewEligibilityMode::class,
            'status' => LessonReviewEligibilityStatus::class,
            'opens_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'source_outcome_version' => 'integer',
            'used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'version' => 'integer',
            'history' => 'array',
            'metadata' => 'array',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function scopeForStudent(Builder $query, int $studentId): Builder
    {
        return $query->where('student_id', $studentId);
    }

    /** @return array<string, mixed> */
    public function snapshotForHistory(): array
    {
        return [
            'version' => $this->version,
            'status' => $this->status->value,
            'eligibility_mode' => $this->eligibility_mode->value,
            'outcome_snapshot' => $this->outcome_snapshot,
            'opens_at' => $this->opens_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'used_at' => $this->used_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'revoked_reason' => $this->revoked_reason,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'eligibility_mode', 'expires_at'])
            ->useLogName('reviews')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
