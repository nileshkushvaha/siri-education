<?php

declare(strict_types=1);

namespace App\Models;

use App\Earnings\Enums\EarningCalculationType;
use App\Earnings\Enums\InstructorEarningStatus;
use App\Earnings\Enums\WithdrawalAllocationStatus;
use App\Earnings\Exceptions\EarningException;
use App\Support\Concerns\PreventsHardDeletion;
use Database\Factories\InstructorEarningFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One earning per canonical source (unique lesson_id for lesson
 * compensation; unique source_type+source_id for every category).
 * Status changes go exclusively through InstructorEarningService —
 * never mutate `status` or amounts directly. Amounts come from the
 * instructor's compensation agreement and are fully independent of
 * student pricing (the commission-era columns were removed in 14.4).
 *
 * The earning's monetary identity (amount/currency) is set once at
 * creation (CreateInstructorEarningFromLessonAction /
 * InstructorPeriodicCompensationService) and never legitimately
 * updated afterward by any code path — unlike status, which cycles
 * through TransitionInstructorEarningAction, there is no authorized-
 * mutation escape hatch here because none is ever needed.
 */
class InstructorEarning extends Model
{
    /** @use HasFactory<InstructorEarningFactory> */
    use HasFactory, HasUuids, LogsActivity, PreventsHardDeletion;

    private const array GUARDED_MONETARY_COLUMNS = ['earning_amount_minor', 'currency_id', 'currency_code'];

    protected static function booted(): void
    {
        static::updating(function (InstructorEarning $earning): void {
            if ($earning->isDirty(self::GUARDED_MONETARY_COLUMNS)) {
                throw new EarningException('An earning\'s amount and currency are set once at creation and may never be modified afterward.');
            }
        });
    }

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
        'earning_amount_minor',
        'calculation_type',
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
     * Admin internals — the calculation snapshot (metadata) and admin
     * notes must never reach any serialization an instructor can see.
     */
    protected $hidden = [
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => InstructorEarningStatus::class,
            'calculation_type' => EarningCalculationType::class,
            'earning_amount_minor' => 'integer',
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

    /** withTrashed() — an archived (soft-deleted) booking must still resolve here; see PreventsHardDeletion. */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class)->withTrashed();
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

    public function withdrawalAllocations(): HasMany
    {
        return $this->hasMany(InstructorWithdrawalAllocation::class, 'instructor_earning_id');
    }

    public function scopeForInstructor(Builder $query, int $instructorId): Builder
    {
        return $query->where('instructor_id', $instructorId);
    }

    public function scopeWithStatus(Builder $query, InstructorEarningStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Released, unassigned earnings — the pool settlement batches draw
     * from. Earnings touched (even partially) by a live or paid-out
     * withdrawal allocation are excluded entirely: settlement batches
     * settle a whole earning at once (no partial-remainder batching),
     * so the same money must never be batch-settled while any portion
     * of it is withdrawal-reserved or already paid out via a withdrawal
     * (`consumed` — invariant #17: settlement and payout execution must
     * never consume the same earning amount).
     */
    public function scopeSettleable(Builder $query): Builder
    {
        return $query
            ->where('status', InstructorEarningStatus::Releasable)
            ->whereNull('settlement_batch_id')
            ->whereDoesntHave('withdrawalAllocations', fn (Builder $q) => $q
                ->whereIn('status', [WithdrawalAllocationStatus::Reserved, WithdrawalAllocationStatus::Consumed]));
    }

    /**
     * The withdrawal pool: released, unassigned earnings in one currency.
     * Reserved allocation amounts are subtracted per row (partial
     * reservations are possible), not by excluding the row.
     */
    public function scopeWithdrawable(Builder $query, int $instructorId, string $currencyCode): Builder
    {
        return $query
            ->where('instructor_id', $instructorId)
            ->where('currency_code', $currencyCode)
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
