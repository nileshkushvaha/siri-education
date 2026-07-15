<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Concerns\PreventsHardDeletion;
use App\Wallet\Enums\WalletLedgerDirection;
use App\Wallet\Enums\WalletLedgerEntryType;
use App\Wallet\Enums\WalletLedgerStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Append-only. A posted row's money fields are never updated — a
 * reversal is always a new offsetting row (see WalletLedgerService::reverse()),
 * with only `status`/`reversed_at` changing on the original.
 */
class WalletLedgerEntry extends Model
{
    use HasFactory, HasUuids, LogsActivity, PreventsHardDeletion;

    protected $fillable = [
        'wallet_id',
        'user_id',
        'currency_id',
        'currency_code',
        'direction',
        'entry_type',
        'amount_minor',
        'balance_after_minor',
        'available_after_minor',
        'held_after_minor',
        'status',
        'source_type',
        'source_id',
        'idempotency_key',
        'description',
        'metadata',
        'posted_at',
        'reversed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'direction' => WalletLedgerDirection::class,
            'entry_type' => WalletLedgerEntryType::class,
            'status' => WalletLedgerStatus::class,
            'amount_minor' => 'integer',
            'balance_after_minor' => 'integer',
            'available_after_minor' => 'integer',
            'held_after_minor' => 'integer',
            'metadata' => 'array',
            'posted_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
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

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForWallet(Builder $query, string $walletId): Builder
    {
        return $query->where('wallet_id', $walletId);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', WalletLedgerStatus::Posted);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['wallet_id', 'direction', 'entry_type', 'amount_minor', 'status'])
            ->useLogName('wallet_ledger_entries')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
