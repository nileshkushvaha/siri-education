<?php

declare(strict_types=1);

namespace App\Models;

use App\Booking\Enums\BookingPaymentReconciliationIssueStatus;
use App\Booking\Enums\BookingPaymentReconciliationIssueType;
use App\Booking\Enums\BookingPaymentReconciliationSeverity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Finance-visible reconciliation queue row for the collection domain —
 * mirrors InstructorPayoutReconciliationIssue's shape exactly, but is a
 * structurally separate model/table (no shared FK, no shared enum).
 * Created/updated exclusively by BookingPaymentReconciliationService;
 * `open_dedupe_key` (DB-level, see the creating migration) makes "at
 * most one open issue per booking payment+type" a database guarantee.
 * Never hard-deleted.
 */
class BookingPaymentReconciliationIssue extends Model
{
    use HasFactory, HasUuids, LogsActivity;

    protected $fillable = [
        'reference',
        'booking_payment_id',
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
            'type' => BookingPaymentReconciliationIssueType::class,
            'severity' => BookingPaymentReconciliationSeverity::class,
            'status' => BookingPaymentReconciliationIssueStatus::class,
            'amount_minor' => 'integer',
            'first_detected_at' => 'immutable_datetime',
            'last_detected_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    public function bookingPayment(): BelongsTo
    {
        return $this->belongsTo(BookingPayment::class);
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
        return $query->where('status', BookingPaymentReconciliationIssueStatus::Open);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'severity', 'type'])
            ->useLogName('payments')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
