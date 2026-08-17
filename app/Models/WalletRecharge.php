<?php

declare(strict_types=1);

namespace App\Models;

use App\Payments\Contracts\Payable;
use App\Wallet\Enums\WalletRechargeStatus;
use Database\Factories\WalletRechargeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A student's intent to add money to their own wallet, and the
 * wallet-domain outcome of that intent. NOT the ledger movement — the
 * actual credit is a WalletLedgerEntry linked back here via
 * source_type/source_id.
 *
 * This row owns NO provider identity. Which gateway was used, which
 * order/payment id it issued, and whether the external charge
 * succeeded are all facts about a `Payment` attempt (this model is its
 * `Payable`). Recharge previously carried `provider`,
 * `provider_order_id` and `provider_payment_id` of its own alongside a
 * bespoke Razorpay integration, which meant SIRI held two independent
 * records of the same external payment that could disagree about
 * whether money had arrived.
 *
 * What remains here is genuinely wallet-domain state: which wallet,
 * how much, in what currency, and how far the CREDIT has got. The
 * credit lifecycle is deliberately separate from the payment
 * lifecycle, because crediting a wallet has real business-level
 * failures that persist (a frozen or closed wallet) — a captured
 * payment whose credit cannot be applied must stay visible and
 * retryable rather than being relabelled a failure. See
 * WalletRechargeStatus.
 *
 * Never stores a raw webhook body, signature, or payment-method detail.
 */
class WalletRecharge extends Model implements Payable
{
    /** @use HasFactory<WalletRechargeFactory> */
    use HasFactory, HasUuids, LogsActivity;

    /** Stable morph alias on payments.payable_type — never a FQCN. */
    public const string PAYABLE_TYPE = 'wallet_recharge';

    protected $fillable = [
        'wallet_id',
        'user_id',
        'amount_minor',
        'currency_code',
        'status',
        'reference',
        'failure_code',
        'failure_reason',
        'metadata',
        'succeeded_at',
        'failed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'status' => WalletRechargeStatus::class,
            'metadata' => 'array',
            'succeeded_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
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

    /**
     * Every external payment attempt made against this recharge,
     * newest first. A recharge may accumulate several: a failed
     * Razorpay order followed by a successful retry. This is the ONLY
     * way to reach provider identity from the wallet domain.
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'payable_id')
            ->where('payable_type', self::PAYABLE_TYPE)
            ->latest('created_at');
    }

    // ── Payable ──────────────────────────────────────────────────────

    public function paymentPayableType(): string
    {
        return self::PAYABLE_TYPE;
    }

    public function paymentPayableId(): string
    {
        return (string) $this->getKey();
    }

    public function paymentAmountMinor(): int
    {
        return (int) $this->amount_minor;
    }

    public function paymentCurrencyCode(): string
    {
        return (string) $this->currency_code;
    }

    public function paymentUserId(): int
    {
        return (int) $this->user_id;
    }

    public function paymentReference(): string
    {
        return (string) $this->reference;
    }

    /**
     * Display/support context only — never personal detail, never
     * anything the student could influence. Deliberately does NOT
     * include the wallet id: it is an internal identifier that would
     * travel to the gateway as order notes for no operational benefit.
     *
     * @return array<string, mixed>
     */
    public function paymentMetadata(): array
    {
        return ['recharge_reference' => $this->reference];
    }

    // ── Queries ──────────────────────────────────────────────────────

    /**
     * Recharges whose CREDIT is unfinished while their payment has
     * already been captured — the wallet-domain half of recovery.
     *
     * Provider polling is deliberately absent: whether the external
     * money arrived is the generic payment reconciliation's question,
     * asked once, for every payable. This scope only finds recharges
     * whose money is known to have landed but whose ledger credit has
     * not yet been applied.
     */
    public function scopeAwaitingCredit(Builder $query): Builder
    {
        return $query->whereIn('status', [
            WalletRechargeStatus::CreditPending,
            WalletRechargeStatus::CreditFailed,
        ]);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'amount_minor', 'currency_code'])
            ->useLogName('wallet_recharges')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
