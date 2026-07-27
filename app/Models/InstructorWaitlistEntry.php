<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WaitlistEntryStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * SRS §6.19/§10.28. Never write `status`/`active_key`
 * directly outside WaitlistService — the active-key invariant
 * (App\Waitlist\Services\WaitlistService::activeKeyFor()) must stay in
 * lockstep with `status` or the database-level duplicate-prevention
 * constraint silently stops working.
 */
class InstructorWaitlistEntry extends Model
{
    use LogsActivity;

    protected $table = 'instructor_waitlist_entries';

    protected $fillable = [
        'student_user_id',
        'instructor_user_id',
        'status',
        'active_key',
        'subject_id',
        'preferred_days',
        'preferred_time_start',
        'preferred_time_end',
        'lesson_duration_minutes',
        'booking_type_preference',
        'recurring_preferred',
        'joined_at',
        'notified_at',
        'fulfilled_at',
        'withdrawn_at',
        'ineligible_at',
        'fulfilled_booking_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => WaitlistEntryStatus::class,
            'preferred_days' => 'array',
            'lesson_duration_minutes' => 'integer',
            'recurring_preferred' => 'boolean',
            'joined_at' => 'immutable_datetime',
            'notified_at' => 'immutable_datetime',
            'fulfilled_at' => 'immutable_datetime',
            'withdrawn_at' => 'immutable_datetime',
            'ineligible_at' => 'immutable_datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_user_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function fulfilledBooking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'fulfilled_booking_id')->withTrashed();
    }

    public function scopeWaiting(Builder $query): Builder
    {
        return $query->where('status', WaitlistEntryStatus::Waiting->value);
    }

    public function scopeForInstructor(Builder $query, int $instructorId): Builder
    {
        return $query->where('instructor_user_id', $instructorId);
    }

    public function scopeForStudent(Builder $query, int $studentId): Builder
    {
        return $query->where('student_user_id', $studentId);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['student_user_id', 'instructor_user_id', 'status'])
            ->useLogName('waitlist')
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
