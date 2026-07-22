<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Concerns\PreventsHardDeletion;
use App\Support\Concerns\PreventsUpdates;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One immutable invoice/receipt per successful booking payment or
 * wallet recharge (SRS §14.21-14.24, GAP-007). Every field here is a
 * snapshot taken at issuance by InvoiceService — never re-derived
 * from the current state of the user, country, source payment, or
 * GeneralSettings. `source_type` is a literal FQCN string (matching
 * wallet_ledger_entries.source_type's own established convention).
 *
 * PreventsHardDeletion + PreventsUpdates together make this row fully
 * immutable at the application layer: no update, no delete, from any
 * code path, ever, including a bare forceFill()->save().
 */
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory, HasUuids, LogsActivity, PreventsHardDeletion, PreventsUpdates;

    protected $fillable = [
        'invoice_number',
        'source_type',
        'source_id',
        'user_id',
        'student_name',
        'billing_country',
        'amount_minor',
        'currency_code',
        'payment_date',
        'payment_reference',
        'service_description',
        'booking_reference',
        'wallet_recharge_reference',
        'organization_name',
        'organization_address',
        'organization_support_email',
        'organization_support_phone',
        'organization_website_url',
        'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'payment_date' => 'immutable_datetime',
            'issued_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['invoice_number', 'source_type', 'source_id', 'user_id'])
            ->useLogName('invoices')
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
