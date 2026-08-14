<?php

declare(strict_types=1);

namespace App\Models;

use App\Payments\Enums\PaymentReconciliationIssueStatus;
use App\Payments\Enums\PaymentReconciliationIssueType;
use App\Support\Concerns\PreventsHardDeletion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Phase 4E.2 — an operator-visible record that a VERIFIED provider
 * event disagreed with our own money, and that settlement was
 * therefore refused.
 *
 * Written exclusively by PaymentReconciliationIssueService, from the
 * one settlement validator both the webhook and the scheduled sweep go
 * through. Nothing student- or instructor-facing can create, see, or
 * touch one.
 *
 * PreventsHardDeletion, no SoftDeletes — a financial discrepancy is
 * history even after it is resolved, exactly like the Payment attempt
 * it describes.
 *
 * This model deliberately exposes NO way to change payment, purchase or
 * entitlement state. `resolve()` writes only this row's own
 * operational columns.
 */
class PaymentReconciliationIssue extends Model
{
    use HasUuids, LogsActivity, PreventsHardDeletion;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $attributes = [
        'status' => 'open',
    ];

    protected $fillable = [
        'payment_id',
        'provider',
        'issue_type',
        'status',
        'expected_amount_minor',
        'observed_amount_minor',
        'expected_currency',
        'observed_currency',
        'first_seen_at',
        'last_seen_at',
        'occurrence_count',
        'resolved_at',
        'resolved_by',
        'resolution_note',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'issue_type' => PaymentReconciliationIssueType::class,
            'status' => PaymentReconciliationIssueStatus::class,
            'expected_amount_minor' => 'integer',
            'observed_amount_minor' => 'integer',
            'occurrence_count' => 'integer',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** @param Builder<self> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', PaymentReconciliationIssueStatus::Open);
    }

    /** @param Builder<self> $query */
    public function scopeForPayment(Builder $query, string $paymentId): void
    {
        $query->where('payment_id', $paymentId);
    }

    public function isOpen(): bool
    {
        return $this->status === PaymentReconciliationIssueStatus::Open;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'issue_type', 'occurrence_count', 'resolved_at'])
            ->useLogName('payment_reconciliation_issues')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
