<?php

declare(strict_types=1);

namespace App\Models;

use App\Earnings\Enums\RazorpayXProviderLinkStatus;
use Database\Factories\InstructorPayoutDestinationProviderLinkFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A payout method's provider-side provisioning state. Every write goes
 * through RazorpayXDestinationProvisioningService (creation/refresh) or
 * RazorpayXDestinationReconciliationService (unknown-outcome
 * resolution) — never mutated ad hoc. Changed bank details never touch
 * an existing `Ready` link; a replacement payout method gets its own
 * link instead.
 */
class InstructorPayoutDestinationProviderLink extends Model
{
    /** @use HasFactory<InstructorPayoutDestinationProviderLinkFactory> */
    use HasFactory, HasUuids, LogsActivity, SoftDeletes;

    protected $fillable = [
        'payout_method_id',
        'instructor_id',
        'provider',
        'provider_contact_reference',
        'bank_details_fingerprint',
    ];

    /**
     * The fingerprint is internal drift-detection material, never
     * returned to any client.
     */
    protected $hidden = [
        'bank_details_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'status' => RazorpayXProviderLinkStatus::class,
            'provisioning_attempts' => 'integer',
            'ip_allowlisting_confirmed_at' => 'immutable_datetime',
            'last_provisioning_attempt_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
        ];
    }

    public function payoutMethod(): BelongsTo
    {
        return $this->belongsTo(InstructorPayoutMethod::class, 'payout_method_id');
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function ipAllowlistingConfirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ip_allowlisting_confirmed_by');
    }

    public function disabler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disabled_by');
    }

    public function scopeForProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }

    public function isReadyForPayout(): bool
    {
        return $this->status->isUsableForPayout()
            && $this->provider_contact_id !== null
            && $this->provider_fund_account_id !== null
            && $this->deleted_at === null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        // Never widen this list to bank_details_fingerprint — drift
        // material stays out of the audit trail.
        return LogOptions::defaults()
            ->logOnly(['status', 'provider_contact_status', 'provider_fund_account_status'])
            ->useLogName('instructor_payouts')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
