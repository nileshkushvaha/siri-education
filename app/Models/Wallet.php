<?php

declare(strict_types=1);

namespace App\Models;

use App\Wallet\Enums\WalletStatus;
use App\Wallet\Exceptions\WalletException;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One wallet per user per currency. Money lives only in the
 * *_minor integer columns — never mutate them directly; every change
 * must go through WalletLedgerService so a ledger entry is always the
 * reason a balance moved. `status = closed` is the terminal state
 * instead of soft-deleting (a wallet with ledger history must never
 * disappear).
 */
class Wallet extends Model
{
    use HasFactory, HasUuids, LogsActivity;

    private const array BALANCE_COLUMNS = ['balance_minor', 'available_balance_minor', 'held_balance_minor'];

    /**
     * Only true while WalletLedgerService::withAuthorizedBalanceMutation()
     * has the lock — an enforced guard, not just a naming convention, so an
     * Eloquent update from anywhere else that touches a balance column
     * fails loudly instead of silently drifting the ledger out of sync.
     */
    private static bool $balanceMutationAuthorized = false;

    protected static function booted(): void
    {
        static::updating(function (Wallet $wallet): void {
            if (! static::$balanceMutationAuthorized && $wallet->isDirty(self::BALANCE_COLUMNS)) {
                throw new WalletException('Wallet balance columns may only be changed through WalletLedgerService.');
            }
        });
    }

    /** @param  Closure(): mixed  $callback */
    public static function withAuthorizedBalanceMutation(Closure $callback): mixed
    {
        static::$balanceMutationAuthorized = true;

        try {
            return $callback();
        } finally {
            static::$balanceMutationAuthorized = false;
        }
    }

    protected $fillable = [
        'user_id',
        'currency_id',
        'currency_code',
        'balance_minor',
        'available_balance_minor',
        'held_balance_minor',
        'status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'balance_minor' => 'integer',
            'available_balance_minor' => 'integer',
            'held_balance_minor' => 'integer',
            'status' => WalletStatus::class,
        ];
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

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(WalletLedgerEntry::class);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', WalletStatus::Active);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['user_id', 'currency_code', 'status'])
            ->useLogName('wallets')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
