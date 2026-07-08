<?php

declare(strict_types=1);

namespace App\Models;

use App\Booking\Enums\BookingPaymentRecordStatus;
use Database\Factories\BookingPaymentFactory;
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

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'provider', 'amount_minor', 'currency_code'])
            ->useLogName('payments')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
