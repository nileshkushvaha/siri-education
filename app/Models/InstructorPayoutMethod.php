<?php

declare(strict_types=1);

namespace App\Models;

use App\Earnings\Enums\PayoutMethodStatus;
use App\Earnings\Enums\PayoutMethodType;
use Database\Factories\InstructorPayoutMethodFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * An instructor payout destination. Sensitive bank details live only in
 * `encrypted_details` (encrypted at rest, hidden from every
 * serialization, decrypted only through
 * InstructorPayoutMethodService::viewSensitiveDetails()). All status
 * changes go exclusively through InstructorPayoutMethodService —
 * verified details are immutable and the model is never hard-deleted.
 */
class InstructorPayoutMethod extends Model
{
    /** @use HasFactory<InstructorPayoutMethodFactory> */
    use HasFactory, HasUuids, LogsActivity, SoftDeletes;

    /**
     * Creation/correction fields only. Lifecycle columns (status,
     * is_default, verification/rejection/disable stamps) are never mass
     * assignable — the service writes them via forceFill after guarding
     * the transition.
     */
    protected $fillable = [
        'instructor_id',
        'type',
        'country_id',
        'currency_id',
        'currency_code',
        'display_label',
        'masked_identifier',
        'fingerprint',
        'encrypted_details',
    ];

    /**
     * The encrypted payload and the keyed fingerprint must never reach
     * any serialization — not instructors, not admins, not logs.
     */
    protected $hidden = [
        'encrypted_details',
        'fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'type' => PayoutMethodType::class,
            'status' => PayoutMethodStatus::class,
            'encrypted_details' => 'encrypted:array',
            'is_default' => 'boolean',
            'submitted_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
        ];
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function rejector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function disabler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disabled_by');
    }

    public function withdrawalRequests(): HasMany
    {
        return $this->hasMany(InstructorWithdrawalRequest::class, 'payout_method_id');
    }

    public function scopeForInstructor(Builder $query, int $instructorId): Builder
    {
        return $query->where('instructor_id', $instructorId);
    }

    /** Verified, enabled methods — the only ones new withdrawals may use. */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('status', PayoutMethodStatus::Verified);
    }

    public function isUsableForWithdrawal(): bool
    {
        return $this->status === PayoutMethodStatus::Verified && $this->deleted_at === null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        // Never widen this list to fingerprint/encrypted_details — the
        // audit trail must stay free of sensitive payout data.
        return LogOptions::defaults()
            ->logOnly(['status', 'is_default', 'display_label'])
            ->useLogName('instructor_payouts')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
