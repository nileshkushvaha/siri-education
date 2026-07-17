<?php

declare(strict_types=1);

namespace App\Models;

use App\Referral\Enums\ReferralCodeStatus;
use App\Support\Concerns\PreventsHardDeletion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A student's permanent referral code. One row per student, globally
 * unique code, stored uppercase-normalized. Never deleted — abuse is
 * handled by disabling (status flips to Disabled with actor/reason),
 * which ReferralCodeService::disable() is the only writer of.
 */
class ReferralCode extends Model
{
    use HasFactory, LogsActivity, PreventsHardDeletion;

    protected $fillable = [
        'user_id',
        'code',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReferralCodeStatus::class,
            'disabled_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function disabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disabled_by');
    }

    public function attributions(): HasMany
    {
        return $this->hasMany(ReferralAttribution::class);
    }

    public function isActive(): bool
    {
        return $this->status === ReferralCodeStatus::Active;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'disabled_at', 'disabled_by', 'disable_reason'])
            ->useLogName('referral_codes')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
