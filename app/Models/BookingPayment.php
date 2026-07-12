<?php

declare(strict_types=1);

namespace App\Models;

use App\Booking\Enums\BookingPaymentRecordStatus;
use Carbon\CarbonInterface;
use Database\Factories\BookingPaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One gateway payment attempt for a booking. Not the wallet ledger —
 * this tracks a booking's order/payment lifecycle with a gateway; it
 * never records money movement between wallets. Never stores card/UPI
 * details or the raw webhook/checkout signature.
 */
class BookingPayment extends Model
{
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

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
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
     * Rows a reconciliation sweep should poll: sent to the gateway
     * (has an order/intent reference) but not yet settled, and either
     * never synced or not synced since the cutoff. Mirrors
     * InstructorPayoutAttempt::scopeReconciliationDue().
     */
    public function scopeReconciliationDue(Builder $query, CarbonInterface $cutoff): Builder
    {
        return $query
            ->whereNotNull('provider_order_id')
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
