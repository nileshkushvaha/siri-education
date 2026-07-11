<?php

declare(strict_types=1);

namespace App\Models;

use App\Earnings\Enums\EarningCalculationType;
use App\Earnings\Enums\InstructorEarningStatus;
use Database\Factories\InstructorEarningFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One earning per lesson (unique lesson_id). Status changes go
 * exclusively through InstructorEarningService — never mutate `status`
 * or amounts directly. Instructor earnings are NOT the student price:
 * the student-facing amount and the platform margin are admin-only and
 * hidden from every serialization by default.
 */
class InstructorEarning extends Model
{
    /** @use HasFactory<InstructorEarningFactory> */
    use HasFactory, HasUuids, LogsActivity;

    protected $fillable = [
        'lesson_id',
        'booking_id',
        'instructor_id',
        'student_id',
        'subject_id',
        'subject_topic_id',
        'academic_level_id',
        'currency_id',
        'currency_code',
        'student_amount_minor',
        'earning_amount_minor',
        'platform_margin_minor',
        'calculation_type',
        'calculation_value',
        'status',
        'hold_until',
        'released_at',
        'settled_at',
        'reversed_at',
        'settlement_batch_id',
        'source_type',
        'source_id',
        'notes',
        'metadata',
        'created_by',
        'updated_by',
    ];

    /**
     * Admin-only money and internals — the instructor must never see
     * the student paid amount, the platform margin, the pricing rule,
     * or admin notes through any serialization.
     */
    protected $hidden = [
        'student_amount_minor',
        'platform_margin_minor',
        'calculation_value',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => InstructorEarningStatus::class,
            'calculation_type' => EarningCalculationType::class,
            'student_amount_minor' => 'integer',
            'earning_amount_minor' => 'integer',
            'platform_margin_minor' => 'integer',
            'calculation_value' => 'decimal:4',
            'hold_until' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
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

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function subjectTopic(): BelongsTo
    {
        return $this->belongsTo(SubjectTopic::class);
    }

    public function settlementBatch(): BelongsTo
    {
        return $this->belongsTo(InstructorSettlementBatch::class, 'settlement_batch_id');
    }

    public function scopeForInstructor(Builder $query, int $instructorId): Builder
    {
        return $query->where('instructor_id', $instructorId);
    }

    public function scopeWithStatus(Builder $query, InstructorEarningStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    /** Released, unassigned earnings — the pool settlement batches draw from. */
    public function scopeSettleable(Builder $query): Builder
    {
        return $query
            ->where('status', InstructorEarningStatus::Releasable)
            ->whereNull('settlement_batch_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'earning_amount_minor', 'settlement_batch_id'])
            ->useLogName('instructor_earnings')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
