<?php

declare(strict_types=1);

namespace App\Models;

use App\Earnings\Enums\InstructorPayoutAttemptStatus;
use App\Earnings\Enums\PayoutFailureCategory;
use Carbon\CarbonInterface;
use Database\Factories\InstructorPayoutAttemptFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One row per logical payout-execution attempt. Created exclusively by
 * InstructorPayoutExecutionService; every status/provider-result column
 * is written via forceFill after the transition guard
 * (InstructorPayoutAttemptStatus::canTransitionTo()) passes. Never
 * hard-deleted; a new attempt gets a new execution_sequence rather than
 * reusing/overwriting a terminal one.
 */
class InstructorPayoutAttempt extends Model
{
    /** @use HasFactory<InstructorPayoutAttemptFactory> */
    use HasFactory, HasUuids, LogsActivity;

    protected $fillable = [
        'reference',
        'withdrawal_request_id',
        'instructor_id',
        'provider',
        'execution_sequence',
        'idempotency_key',
        'request_fingerprint',
        'amount_minor',
        'currency_id',
        'currency_code',
        'initiated_by',
        'requested_fake_scenario',
    ];

    /**
     * Idempotency key and request fingerprint are internal replay-safety
     * material, never returned to any client; encrypted provider
     * metadata may carry provider-specific identifiers that are safe to
     * store but not to broadcast; requested_fake_scenario is a test/dev
     * hook, never real data.
     */
    protected $hidden = [
        'idempotency_key',
        'request_fingerprint',
        'encrypted_provider_metadata',
        'requested_fake_scenario',
    ];

    protected function casts(): array
    {
        return [
            'status' => InstructorPayoutAttemptStatus::class,
            'failure_category' => PayoutFailureCategory::class,
            'execution_sequence' => 'integer',
            'amount_minor' => 'integer',
            'attempt_count' => 'integer',
            'encrypted_provider_metadata' => 'encrypted:array',
            'provider_created_at' => 'immutable_datetime',
            'provider_processed_at' => 'immutable_datetime',
            'initiated_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
            'next_retry_at' => 'immutable_datetime',
        ];
    }

    public function withdrawalRequest(): BelongsTo
    {
        return $this->belongsTo(InstructorWithdrawalRequest::class, 'withdrawal_request_id');
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function reconciliationIssues(): HasMany
    {
        return $this->hasMany(InstructorPayoutReconciliationIssue::class, 'payout_attempt_id');
    }

    public function scopeForWithdrawal(Builder $query, string $withdrawalRequestId): Builder
    {
        return $query->where('withdrawal_request_id', $withdrawalRequestId);
    }

    /** Attempts whose provider outcome is not yet certain and due for a reconciliation poll. */
    public function scopeReconciliationDue(Builder $query, CarbonInterface $cutoff): Builder
    {
        return $query
            ->whereIn('status', [InstructorPayoutAttemptStatus::Processing, InstructorPayoutAttemptStatus::Unknown, InstructorPayoutAttemptStatus::Submitted, InstructorPayoutAttemptStatus::Acknowledged])
            ->where(fn (Builder $q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<=', $cutoff));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'amount_minor', 'currency_code'])
            ->useLogName('instructor_payout_execution')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
