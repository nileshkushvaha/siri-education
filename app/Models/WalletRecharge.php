<?php

declare(strict_types=1);

namespace App\Models;

use App\Wallet\Enums\WalletRechargeStatus;
use Carbon\CarbonInterface;
use Database\Factories\WalletRechargeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One gateway wallet-recharge attempt. Not the wallet ledger itself —
 * a succeeded row never records the wallet's own balance movement,
 * only that a recharge was captured; the actual credit lives on its
 * own WalletLedgerEntry, linked back here via source_type/source_id.
 * Never stores a raw webhook body, signature, or payment-method detail.
 */
class WalletRecharge extends Model
{
    /** @use HasFactory<WalletRechargeFactory> */
    use HasFactory, HasUuids, LogsActivity;

    protected $fillable = [
        'wallet_id',
        'user_id',
        'provider',
        'provider_order_id',
        'provider_payment_id',
        'amount_minor',
        'currency_code',
        'status',
        'idempotency_key',
        'failure_code',
        'failure_reason',
        'metadata',
        'provider_confirmed_at',
        'succeeded_at',
        'failed_at',
        'last_synced_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'status' => WalletRechargeStatus::class,
            'metadata' => 'array',
            'provider_confirmed_at' => 'immutable_datetime',
            'succeeded_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Rows a reconciliation sweep should poll: not terminal, and either never synced or not synced since the cutoff. */
    public function scopeReconciliationDue(Builder $query, CarbonInterface $cutoff): Builder
    {
        return $query
            ->whereIn('status', [
                WalletRechargeStatus::ProviderCreated,
                WalletRechargeStatus::AwaitingConfirmation,
                WalletRechargeStatus::CreditPending,
                WalletRechargeStatus::CreditFailed,
            ])
            ->where(fn (Builder $q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<=', $cutoff));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'provider', 'amount_minor', 'currency_code'])
            ->useLogName('wallet_recharges')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
