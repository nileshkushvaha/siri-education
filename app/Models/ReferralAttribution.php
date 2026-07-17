<?php

declare(strict_types=1);

namespace App\Models;

use App\Referral\Enums\ReferralAttributionSource;
use App\Support\Concerns\PreventsHardDeletion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The permanent referrer relationship, created exactly once when a new
 * student registers with a valid referral code. The unique index on
 * referred_student_id is the single-referrer invariant; a DB CHECK
 * blocks self-referral. No update or delete workflow exists in Phase
 * 19B — admin correction arrives in a later audited phase.
 */
class ReferralAttribution extends Model
{
    use HasFactory, LogsActivity, PreventsHardDeletion;

    protected $fillable = [
        'referrer_id',
        'referred_student_id',
        'referral_code_id',
        'source',
        'attributed_at',
    ];

    protected function casts(): array
    {
        return [
            'source' => ReferralAttributionSource::class,
            'attributed_at' => 'immutable_datetime',
        ];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function referredStudent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_student_id');
    }

    public function referralCode(): BelongsTo
    {
        return $this->belongsTo(ReferralCode::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['referrer_id', 'referred_student_id', 'referral_code_id', 'source'])
            ->useLogName('referral_attributions')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
