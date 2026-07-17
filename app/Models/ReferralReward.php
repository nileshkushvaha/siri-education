<?php

declare(strict_types=1);

namespace App\Models;

use App\Referral\Enums\ReferralRewardStatus;
use App\Referral\Enums\ReferralRewardType;
use App\Support\Concerns\PreventsHardDeletion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One referral reward per eligible completed paid lesson — the
 * calculation snapshot (lesson amount/currency + campaign type/value
 * at evaluation time + the computed integer amount) is immutable
 * history; later campaign edits never change it. Status moves only
 * through ReferralRewardService; unique(lesson_id) and
 * unique(attribution_id, class_sequence) are the concurrency guards
 * of last resort; DB CHECKs pin ledger linkage to the credited and
 * reversed statuses.
 */
class ReferralReward extends Model
{
    use HasFactory, LogsActivity, PreventsHardDeletion;

    protected $fillable = [
        'attribution_id',
        'campaign_id',
        'referrer_id',
        'referred_student_id',
        'lesson_id',
        'booking_id',
        'class_sequence',
        'lesson_amount_minor',
        'lesson_currency_code',
        'reward_type',
        'reward_value',
        'reward_amount_minor',
        'reward_currency_code',
        'status',
        'hold_reason',
        'decision_reason',
        'eligible_at',
        'credit_ready_at',
        'rejected_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReferralRewardStatus::class,
            'reward_type' => ReferralRewardType::class,
            'class_sequence' => 'integer',
            'lesson_amount_minor' => 'integer',
            'reward_value' => 'integer',
            'reward_amount_minor' => 'integer',
            'eligible_at' => 'immutable_datetime',
            'credit_ready_at' => 'immutable_datetime',
            'credited_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
        ];
    }

    public function attribution(): BelongsTo
    {
        return $this->belongsTo(ReferralAttribution::class, 'attribution_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(ReferralCampaign::class, 'campaign_id');
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function referredStudent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_student_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function walletLedgerEntry(): BelongsTo
    {
        return $this->belongsTo(WalletLedgerEntry::class, 'wallet_ledger_entry_id');
    }

    public function reversalLedgerEntry(): BelongsTo
    {
        return $this->belongsTo(WalletLedgerEntry::class, 'reversal_ledger_entry_id');
    }

    /** The sweep's selection: eligible and past its readiness instant. */
    public function scopeReadyForCredit(Builder $query, \DateTimeInterface $now): Builder
    {
        return $query
            ->where('status', ReferralRewardStatus::Eligible)
            ->whereNotNull('credit_ready_at')
            ->where('credit_ready_at', '<=', $now);
    }

    public function scopeForReferrer(Builder $query, int $referrerId): Builder
    {
        return $query->where('referrer_id', $referrerId);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'hold_reason', 'decision_reason', 'reward_amount_minor', 'reward_currency_code'])
            ->useLogName('referral_rewards')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
