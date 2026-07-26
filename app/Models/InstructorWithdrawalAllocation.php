<?php

declare(strict_types=1);

namespace App\Models;

use App\Earnings\Enums\WithdrawalAllocationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One reservation of (part of) an earning against a withdrawal
 * request. Written exclusively by InstructorWithdrawalService inside
 * the same transaction as the withdrawal itself; never hard-deleted —
 * released rows stay as financial history.
 */
class InstructorWithdrawalAllocation extends Model
{
    use HasUuids, LogsActivity;

    protected $fillable = [
        'withdrawal_request_id',
        'instructor_earning_id',
        'currency_id',
        'currency_code',
        'amount_minor',
        'status',
        'reserved_at',
        'released_at',
        'consumed_at',
        'reversed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => WithdrawalAllocationStatus::class,
            'amount_minor' => 'integer',
            'reserved_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
        ];
    }

    public function withdrawalRequest(): BelongsTo
    {
        return $this->belongsTo(InstructorWithdrawalRequest::class, 'withdrawal_request_id');
    }

    public function earning(): BelongsTo
    {
        return $this->belongsTo(InstructorEarning::class, 'instructor_earning_id');
    }

    /** Live reservations — the amounts subtracted from available balance. */
    public function scopeReserved(Builder $query): Builder
    {
        return $query->where('status', WithdrawalAllocationStatus::Reserved);
    }

    /** Reserved or consumed — both count as "unavailable" for balance purposes. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', [WithdrawalAllocationStatus::Reserved, WithdrawalAllocationStatus::Consumed]);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'amount_minor'])
            ->useLogName('instructor_payouts')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
