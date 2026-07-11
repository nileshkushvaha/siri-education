<?php

declare(strict_types=1);

namespace App\Models;

use App\Earnings\Enums\CompensationExceptionCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One open compensation exception per blocked lesson — the admin queue
 * row behind the Compensation Exceptions page. Written exclusively by
 * CompensationExceptionService; resolved (never deleted) once the
 * earning exists.
 */
class InstructorCompensationException extends Model
{
    use HasUuids;

    protected $fillable = [
        'lesson_id',
        'booking_id',
        'instructor_id',
        'scheduled_start_at',
        'category',
        'reason',
        'retry_eligible',
        'attempt_count',
        'first_failed_at',
        'last_attempt_at',
        'resolved_at',
        'resolved_earning_id',
    ];

    protected function casts(): array
    {
        return [
            'category' => CompensationExceptionCategory::class,
            'retry_eligible' => 'boolean',
            'attempt_count' => 'integer',
            'scheduled_start_at' => 'immutable_datetime',
            'first_failed_at' => 'immutable_datetime',
            'last_attempt_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
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

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function resolvedEarning(): BelongsTo
    {
        return $this->belongsTo(InstructorEarning::class, 'resolved_earning_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeRetryable(Builder $query): Builder
    {
        return $query->open()->where('retry_eligible', true);
    }
}
