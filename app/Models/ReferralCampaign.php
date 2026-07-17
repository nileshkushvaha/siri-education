<?php

declare(strict_types=1);

namespace App\Models;

use App\Referral\Enums\ReferralCampaignStatus;
use App\Referral\Enums\ReferralRewardTiming;
use App\Referral\Enums\ReferralRewardType;
use App\Support\Concerns\PreventsHardDeletion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A referral campaign — the single authoritative source of reward
 * rules (SRS 16.22). reward_value is an integer: basis points for
 * percentage campaigns, minor units (in reward_currency_code) for
 * fixed campaigns — never a float. Windows are UTC and half-open:
 * [starts_at, ends_at). No pivot rows in eligibleCountries means the
 * campaign applies to all countries. Status moves only through
 * ReferralCampaignService; Archived campaigns are preserved forever
 * (PreventsHardDeletion, no soft delete).
 */
class ReferralCampaign extends Model
{
    use HasFactory, LogsActivity, PreventsHardDeletion;

    protected $fillable = [
        'name',
        'description',
        'status',
        'starts_at',
        'ends_at',
        'reward_type',
        'reward_value',
        'reward_currency_id',
        'reward_currency_code',
        'min_completed_paid_lessons',
        'max_rewarded_classes',
        'reward_timing',
        'hold_days',
        'requires_fraud_review',
        'terms',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReferralCampaignStatus::class,
            'reward_type' => ReferralRewardType::class,
            'reward_timing' => ReferralRewardTiming::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'reward_value' => 'integer',
            'min_completed_paid_lessons' => 'integer',
            'max_rewarded_classes' => 'integer',
            'hold_days' => 'integer',
            'requires_fraud_review' => 'boolean',
        ];
    }

    public function rewardCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'reward_currency_id');
    }

    public function eligibleCountries(): BelongsToMany
    {
        return $this->belongsToMany(Country::class, 'referral_campaign_countries')->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function appliesToAllCountries(): bool
    {
        return $this->eligibleCountries()->count() === 0;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ReferralCampaignStatus::Active);
    }

    /** Half-open UTC window: starts_at <= at < ends_at. */
    public function scopeCoveringInstant(Builder $query, \DateTimeInterface $at): Builder
    {
        return $query
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>', $at);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'status', 'starts_at', 'ends_at', 'reward_type', 'reward_value',
                'reward_currency_code', 'min_completed_paid_lessons',
                'max_rewarded_classes', 'reward_timing', 'hold_days',
                'requires_fraud_review',
            ])
            ->useLogName('referral_campaigns')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
