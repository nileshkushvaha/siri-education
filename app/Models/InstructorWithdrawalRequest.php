<?php

declare(strict_types=1);

namespace App\Models;

use App\Earnings\Enums\InstructorWithdrawalStatus;
use App\Earnings\Enums\PayoutMethodType;
use Database\Factories\InstructorWithdrawalRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * An instructor withdrawal request. Created exclusively by
 * InstructorWithdrawalService inside a locked transaction; status moves
 * only through that service's transition guard. The payment destination
 * is frozen at creation into `encrypted_payout_method_snapshot`
 * (encrypted, hidden, never regenerated) — the public surface is only
 * payout_method_label / masked_identifier / payout_method_type. Money
 * is integer minor units; rows are never deleted.
 */
class InstructorWithdrawalRequest extends Model
{
    /** @use HasFactory<InstructorWithdrawalRequestFactory> */
    use HasFactory, HasUuids, LogsActivity;

    /**
     * Creation fields only. `status` and every review/approval column
     * are never mass assignable — InstructorWithdrawalService writes
     * them via forceFill after guarding the transition.
     */
    protected $fillable = [
        'reference',
        'instructor_id',
        'payout_method_id',
        'currency_id',
        'currency_code',
        'amount_minor',
        'fee_minor',
        'net_amount_minor',
        'available_balance_before_minor',
        'available_balance_after_minor',
        'payout_method_type',
        'payout_method_label',
        'masked_identifier',
        'encrypted_payout_method_snapshot',
        'idempotency_key',
        'instructor_note',
        'requested_at',
    ];

    /**
     * The encrypted destination snapshot and admin internals must never
     * reach any serialization an instructor (or a log) can see. Review
     * outcomes reach the instructor through explicit UI fields, not raw
     * model output.
     */
    protected $hidden = [
        'encrypted_payout_method_snapshot',
        'internal_review_note',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'status' => InstructorWithdrawalStatus::class,
            'payout_method_type' => PayoutMethodType::class,
            'amount_minor' => 'integer',
            'fee_minor' => 'integer',
            'net_amount_minor' => 'integer',
            'available_balance_before_minor' => 'integer',
            'available_balance_after_minor' => 'integer',
            'encrypted_payout_method_snapshot' => 'encrypted:array',
            'requested_at' => 'immutable_datetime',
            'review_started_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'processing_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
            'recovered_at' => 'immutable_datetime',
        ];
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function payoutMethod(): BelongsTo
    {
        return $this->belongsTo(InstructorPayoutMethod::class, 'payout_method_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'review_started_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(InstructorWithdrawalAllocation::class, 'withdrawal_request_id');
    }

    public function payoutAttempts(): HasMany
    {
        return $this->hasMany(InstructorPayoutAttempt::class, 'withdrawal_request_id');
    }

    public function reconciliationIssues(): HasMany
    {
        return $this->hasMany(InstructorPayoutReconciliationIssue::class, 'withdrawal_request_id');
    }

    public function scopeForInstructor(Builder $query, int $instructorId): Builder
    {
        return $query->where('instructor_id', $instructorId);
    }

    /** Requests currently holding reserved earnings (or about to). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            InstructorWithdrawalStatus::Submitted,
            InstructorWithdrawalStatus::UnderReview,
            InstructorWithdrawalStatus::Approved,
            InstructorWithdrawalStatus::Processing,
        ]);
    }

    public function getActivitylogOptions(): LogOptions
    {
        // Safe metadata only — never the snapshot or review internals.
        return LogOptions::defaults()
            ->logOnly(['status', 'amount_minor', 'currency_code'])
            ->useLogName('instructor_payouts')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
