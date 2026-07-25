<?php

declare(strict_types=1);

namespace App\Models;

use App\PromotionalCredits\Enums\PromotionalCreditCampaignStatus;
use App\Support\Concerns\PreventsHardDeletion;
use Database\Factories\PromotionalCreditCampaignFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A promotional-credit campaign (GAP-041, SRS §16.17-§16.19) — the
 * single authoritative source of a campaign's fixed credit amount,
 * currency, and limits. Status moves only through
 * PromotionalCreditService; Archived campaigns are preserved forever
 * (PreventsHardDeletion, no soft delete), mirroring ReferralCampaign.
 */
class PromotionalCreditCampaign extends Model
{
    /** @use HasFactory<PromotionalCreditCampaignFactory> */
    use HasFactory, LogsActivity, PreventsHardDeletion;

    protected $fillable = [
        'name',
        'description',
        'status',
        'starts_at',
        'ends_at',
        'amount_minor',
        'currency_id',
        'currency_code',
        'per_student_limit',
        'total_budget_minor',
        'terms',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => PromotionalCreditCampaignStatus::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'amount_minor' => 'integer',
            'per_student_limit' => 'integer',
            'total_budget_minor' => 'integer',
        ];
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function issuances(): HasMany
    {
        return $this->hasMany(PromotionalCreditIssuance::class, 'campaign_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', PromotionalCreditCampaignStatus::Active);
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
            ->logOnly(['status', 'starts_at', 'ends_at', 'amount_minor', 'currency_code', 'per_student_limit', 'total_budget_minor'])
            ->useLogName('promotional_credits')
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at']);
    }
}
