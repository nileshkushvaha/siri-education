<?php

declare(strict_types=1);

namespace App\Models;

use App\Booking\Enums\BookingPaymentRecordStatus;
use App\Payments\Contracts\Payable;
use App\Payments\Enums\PaymentStatus;
use Carbon\CarbonInterface;
use Database\Factories\BookingPaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The commercial obligation to pay for a booking — "this student owes
 * this amount, in this currency, for this booking".
 *
 * Historically this row ALSO wore attempt clothing: it carries
 * provider/provider_order_id/provider_payment_id/idempotency_key and a
 * per-attempt status, because the legacy Booking flow had nowhere else
 * to put them. That dual role is what PAY-4 unwinds. The obligation is
 * the durable half; the provider round-trips are the disposable half,
 * and they belong on the generic `payments` attempt ledger.
 *
 * Booking now collects through that ledger: this row is created once
 * per booking and never again, and every provider round-trip is a
 * separate Payment attempt. A student who fails twice and succeeds on
 * the third try owes one amount, and all three attempts survive.
 *
 * Its morph identity is the stable alias `booking_payment`, used
 * consistently everywhere including the payments ledger and the
 * activity log. (PAY-4A briefly pinned getMorphClass() to the FQCN to
 * protect historical polymorphic rows; an audit proved there are none,
 * and the pin had to go because the attempt relations below resolve
 * through the same alias the ledger stores.)
 *
 * Wallet-funded rows stay outside this entirely (provider = 'wallet',
 * no provider_order_id/provider_payment_id, no webhook). A wallet
 * payment is an internal funding movement, not an external provider
 * attempt; the actual debit lives on its own WalletLedgerEntry, linked
 * back here via source_type/source_id. Never stores card/UPI details
 * or the raw webhook/checkout signature.
 */
class BookingPayment extends Model implements Payable
{
    /**
     * Stable morph alias persisted in `payments.payable_type` — never
     * the FQCN. Registered in PaymentServiceProvider.
     */
    public const string PAYABLE_TYPE = 'booking_payment';

    /** @use HasFactory<BookingPaymentFactory> */
    use HasFactory, HasUuids, LogsActivity;

    protected $fillable = [
        'booking_id',
        'user_id',
        'provider',
        'provider_order_id',
        'provider_payment_id',
        'amount_minor',
        'currency_code',
        'status',
        'payment_method',
        'idempotency_key',
        'metadata',
        'paid_at',
        'failed_at',
        'last_synced_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'status' => BookingPaymentRecordStatus::class,
            'metadata' => 'array',
            'paid_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
        ];
    }

    /** withTrashed() — an archived (soft-deleted) booking must still resolve here; see PreventsHardDeletion. */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class)->withTrashed();
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
     * The external provider attempts made against this obligation.
     *
     * Many attempts, at most one open — the cardinality is enforced by
     * the unique index on payments, not by application code. A failed
     * attempt stays failed forever; a retry is a NEW row, which is the
     * whole reason collection moved off this table.
     */
    public function paymentAttempts(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable', 'payable_type', 'payable_id')
            ->orderByDesc('created_at');
    }

    /**
     * The attempt that actually collected the money, if any.
     *
     * This is where refund, support and admin resolve provider identity
     * from. Deliberately a relation rather than provider ids copied back
     * onto this row: duplicated identifiers drift, and the obligation
     * has no business knowing which of several attempts happened to win.
     */
    public function settledPayment(): MorphOne
    {
        return $this->morphOne(Payment::class, 'payable', 'payable_type', 'payable_id')
            ->where('status', PaymentStatus::Paid->value)
            ->latestOfMany('paid_at');
    }

    /*
    |--------------------------------------------------------------------------
    | Payable — PAY-4A
    |--------------------------------------------------------------------------
    |
    | Every method below answers from the obligation SNAPSHOT already
    | stored on this row. None of them consults the pricing matrix, the
    | student's country, the current currency setting, or the provider.
    | A booking priced last March stays priced as it was priced last
    | March, however many times it is attempted and whatever the
    | catalogue says today.
    |
    | None of them touches auth() either: Payable methods run inside
    | webhooks, the scheduler, and queued reconciliation, where there is
    | no session and auth() is simply null.
    */

    public function paymentPayableType(): string
    {
        return self::PAYABLE_TYPE;
    }

    public function paymentPayableId(): string
    {
        return (string) $this->getKey();
    }

    /** Already integer minor units on the row — no float, no formatting, no FX, no recalculation. */
    public function paymentAmountMinor(): int
    {
        return (int) $this->amount_minor;
    }

    /**
     * The currency this obligation was DENOMINATED in when created.
     *
     * Deliberately not derived from the student's country or the
     * current pricing configuration: re-deriving currency would let a
     * historical obligation silently change denomination when a student
     * relocates or the catalogue is re-priced.
     */
    public function paymentCurrencyCode(): string
    {
        return (string) $this->currency_code;
    }

    /**
     * The student who owes this amount. Traced from the obligation,
     * never from the session.
     *
     * Falls back to the booking's student because `user_id` is nullable
     * on this table: without the fallback a null denormalised owner
     * becomes the integer 0, which is not a real user and violates the
     * attempt ledger's foreign key at insert time.
     */
    public function paymentUserId(): int
    {
        return (int) ($this->user_id ?? $this->booking?->student_id);
    }

    /**
     * The booking reference — the identifier a student sees on their
     * confirmation and an operator searches by.
     *
     * This is the OBLIGATION reference, and it stays the same across
     * every attempt made against it. It is deliberately NOT
     * `idempotency_key`: that is attempt-scoped identity owned by the
     * payment kernel, and reusing it here would re-create exactly the
     * obligation/attempt conflation PAY-4 exists to unwind.
     *
     * Falls back to this row's own key only if the booking is somehow
     * unresolvable, so a reference is always available for support.
     */
    public function paymentReference(): string
    {
        $reference = $this->booking?->reference;

        return blank($reference) ? (string) $this->getKey() : (string) $reference;
    }

    /**
     * Support/reconciliation context only. No credentials, no
     * signatures, no card or UPI details, no PII beyond the ids an
     * operator already has access to.
     *
     * @return array<string, mixed>
     */
    public function paymentMetadata(): array
    {
        return array_filter([
            'booking_payment_id' => (string) $this->getKey(),
            'booking_id' => $this->booking_id,
            'booking_reference' => $this->booking?->reference,
            'student_id' => $this->user_id,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Rows a reconciliation sweep should poll: sent to the gateway
     * (has an order/intent reference) but not yet settled, and either
     * never synced or not synced since the cutoff. Mirrors
     * InstructorPayoutAttempt::scopeReconciliationDue().
     */
    public function scopeReconciliationDue(Builder $query, CarbonInterface $cutoff): Builder
    {
        return $query
            ->whereHas('paymentAttempts')
            ->whereIn('status', [
                BookingPaymentRecordStatus::Pending,
                BookingPaymentRecordStatus::Authorized,
                BookingPaymentRecordStatus::Processing,
                BookingPaymentRecordStatus::Unknown,
                BookingPaymentRecordStatus::ResolutionRequired,
            ])
            ->where(fn (Builder $q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<=', $cutoff));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'provider', 'amount_minor', 'currency_code'])
            ->useLogName('payments')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
