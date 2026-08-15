<?php

declare(strict_types=1);

namespace App\Models;

use App\Payments\Enums\PaymentStatus;
use App\Support\Concerns\PreventsHardDeletion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Phase 4B.1 — ONE generic gateway payment attempt against a
 * App\Payments\Contracts\Payable.
 *
 * This is an ATTEMPT, not a purchase. A Payable may have many
 * attempts (failed Razorpay order, then a successful one); the payable
 * itself owns the "did this ultimately get paid for" question. Never
 * mutate a settled attempt to reuse it for a retry — create a new row,
 * so failed-attempt history survives for support and reconciliation.
 *
 * Deliberately separate from the legacy `BookingPayment` and
 * `WalletRecharge` records, which are untouched by this phase. The
 * shared gateway/signature layer is common to all three, so no
 * Razorpay/Stripe SDK logic is duplicated — see
 * docs/generic-payable-payment-foundation.md.
 *
 * PreventsHardDeletion, no SoftDeletes: a payment attempt is financial
 * history and is never removed.
 */
class Payment extends Model
{
    use HasUuids, LogsActivity, PreventsHardDeletion;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $attributes = [
        'status' => 'pending',
    ];

    protected $fillable = [
        'payable_type',
        'payable_id',
        'user_id',
        'provider',
        'provider_order_id',
        'provider_payment_id',
        'amount_minor',
        'currency_code',
        'status',
        'idempotency_key',
        'failure_code',
        'failure_message',
        'metadata',
        'paid_at',
        'failed_at',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount_minor' => 'integer',
            'metadata' => 'array',
            'paid_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
            // Cast, but deliberately NOT fillable: the initialization
            // claim is an atomic conditional UPDATE, never a mass
            // assignment. Without the cast it hydrated as a raw string
            // while every sibling timestamp came back as Carbon.
            'initialization_claimed_at' => 'immutable_datetime',
        ];
    }

    /** Resolved through the morph map registered in PaymentServiceProvider — `payable_type` is an alias, never a FQCN. */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForPayable(Builder $query, string $payableType, string $payableId): Builder
    {
        return $query->where('payable_type', $payableType)->where('payable_id', $payableId);
    }

    /** Attempts still awaiting a provider outcome. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Processing->value]);
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::Paid);
    }

    /**
     * Open attempts old enough to be worth asking the provider about,
     * and not polled recently. Mirrors WalletRecharge's identical
     * scope — an attempt that has no provider order was never handed
     * to a gateway, so there is nothing to poll.
     */
    public function scopeReconciliationDue(Builder $query, \DateTimeInterface $cutoff): Builder
    {
        return $query->open()
            ->whereNotNull('provider_order_id')
            ->where('created_at', '<=', $cutoff)
            ->where(fn (Builder $q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<=', $cutoff));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'provider', 'provider_order_id', 'provider_payment_id', 'amount_minor'])
            ->useLogName('payments')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
