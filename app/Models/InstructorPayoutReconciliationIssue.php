<?php

declare(strict_types=1);

namespace App\Models;

use App\Earnings\Enums\PayoutReconciliationIssueStatus;
use App\Earnings\Enums\PayoutReconciliationIssueType;
use App\Earnings\Enums\PayoutReconciliationSeverity;
use Database\Factories\InstructorPayoutReconciliationIssueFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Finance-visible reconciliation queue row. Created/updated exclusively
 * by InstructorPayoutReconciliationService; `open_dedupe_key` (DB-level,
 * see the creating migration) makes "at most one open issue per
 * withdrawal+type" a database guarantee, not just an application-level
 * check. Never hard-deleted; resolution/ignore keeps full history.
 */
class InstructorPayoutReconciliationIssue extends Model
{
    /** @use HasFactory<InstructorPayoutReconciliationIssueFactory> */
    use HasFactory, HasUuids, LogsActivity;

    protected $fillable = [
        'reference',
        'withdrawal_request_id',
        'payout_attempt_id',
        'provider',
        'type',
        'severity',
        'local_status',
        'provider_status',
        'amount_minor',
        'currency_code',
        'safe_summary',
        'first_detected_at',
        'last_detected_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => PayoutReconciliationIssueType::class,
            'severity' => PayoutReconciliationSeverity::class,
            'status' => PayoutReconciliationIssueStatus::class,
            'amount_minor' => 'integer',
            'first_detected_at' => 'immutable_datetime',
            'last_detected_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    public function withdrawalRequest(): BelongsTo
    {
        return $this->belongsTo(InstructorWithdrawalRequest::class, 'withdrawal_request_id');
    }

    public function payoutAttempt(): BelongsTo
    {
        return $this->belongsTo(InstructorPayoutAttempt::class, 'payout_attempt_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', PayoutReconciliationIssueStatus::Open);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'severity', 'type'])
            ->useLogName('instructor_payout_execution')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
