<?php

declare(strict_types=1);

namespace App\Referral\Services;

use App\Models\Currency;
use App\Models\ReferralCampaign;
use App\Models\ReferralReward;
use App\Models\User;
use App\Referral\Contracts\ReferralCampaignServiceInterface;
use App\Referral\DTOs\ReferralCampaignData;
use App\Referral\Enums\ReferralCampaignStatus;
use App\Referral\Enums\ReferralRewardTiming;
use App\Referral\Enums\ReferralRewardType;
use App\Referral\Exceptions\ReferralException;
use App\Services\AuditTrailService;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The single writer of referral campaigns and their status (SRS
 * 16.22/16.23). Every mutation authorizes ManageReferralCampaigns
 * independently of Filament, validates the reward rules, runs under a
 * row lock, and audits through AuditTrailService with safe
 * before/after values — never the raw admin payload or full terms.
 *
 * Overlap policy (Phase 19C decision): the SRS defines no campaign
 * priority, so overlapping ACTIVE windows with an intersecting country
 * scope are prevented at activation — the safety rule the Phase 19A/C
 * audits preferred. activeCampaignFor() therefore normally finds at
 * most one match, but still orders deterministically (earliest
 * starts_at, then lowest id) so bad data can never make reward
 * evaluation ambiguous.
 *
 * This service never touches wallets, rewards, lessons or listeners —
 * reward processing is Phase 19D.
 */
final class ReferralCampaignService implements ReferralCampaignServiceInterface
{
    private const string LOG_NAME = 'referral_campaigns';

    /** Safe ceiling for percentage campaigns: 100% of the lesson amount. */
    private const int MAX_PERCENTAGE_BASIS_POINTS = 10000;

    public function __construct(
        private readonly AuditTrailService $auditTrail,
    ) {}

    public function create(ReferralCampaignData $data, User $admin): ReferralCampaign
    {
        $this->assertCanManage($admin);
        $this->assertValidRules($data);

        $campaign = DB::transaction(function () use ($data, $admin): ReferralCampaign {
            $campaign = ReferralCampaign::query()->create([
                ...$this->attributesFrom($data),
                'status' => ReferralCampaignStatus::Draft,
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]);

            $campaign->eligibleCountries()->sync($data->eligibleCountryIds);

            return $campaign;
        });

        $this->auditTrail->logUser(
            $admin,
            self::LOG_NAME,
            'campaign_created',
            sprintf('Referral campaign "%s" created as draft.', $campaign->name),
            $campaign,
            $this->safeMeta($campaign),
        );

        return $campaign;
    }

    public function update(ReferralCampaign $campaign, ReferralCampaignData $data, User $admin): ReferralCampaign
    {
        $this->assertCanManage($admin);
        $this->assertValidRules($data);

        [$campaign, $before] = DB::transaction(function () use ($campaign, $data, $admin): array {
            $campaign = $this->locked($campaign);

            if (! $campaign->status->isEditable()) {
                throw new ReferralException(sprintf(
                    'A %s campaign cannot be edited — pause it first, or archive and create a successor.',
                    $campaign->status->value,
                ));
            }

            $this->assertRewardRulesStillMutable($campaign, $data);

            $before = $this->safeMeta($campaign);

            $campaign->fill($this->attributesFrom($data));
            $campaign->updated_by = $admin->id;
            $campaign->save();

            $campaign->eligibleCountries()->sync($data->eligibleCountryIds);

            return [$campaign, $before];
        });

        $this->auditTrail->logUser(
            $admin,
            self::LOG_NAME,
            'campaign_rules_updated',
            sprintf('Referral campaign "%s" rules updated.', $campaign->name),
            $campaign,
            ['before' => $before, 'after' => $this->safeMeta($campaign->refresh())],
        );

        return $campaign;
    }

    public function activate(ReferralCampaign $campaign, User $admin, string $reason): ReferralCampaign
    {
        return $this->transition($campaign, ReferralCampaignStatus::Active, $admin, $reason, 'campaign_activated', function (ReferralCampaign $locked): void {
            if ($locked->ends_at->getTimestamp() <= now()->getTimestamp()) {
                throw new ReferralException('An expired campaign window cannot be activated — extend ends_at first.');
            }

            $this->assertNoActiveOverlap($locked);
        });
    }

    public function pause(ReferralCampaign $campaign, User $admin, string $reason): ReferralCampaign
    {
        return $this->transition($campaign, ReferralCampaignStatus::Paused, $admin, $reason, 'campaign_paused');
    }

    public function resume(ReferralCampaign $campaign, User $admin, string $reason): ReferralCampaign
    {
        if ($campaign->status !== ReferralCampaignStatus::Paused) {
            throw new ReferralException('Only a paused campaign can be resumed.');
        }

        return $this->transition($campaign, ReferralCampaignStatus::Active, $admin, $reason, 'campaign_resumed', function (ReferralCampaign $locked): void {
            if ($locked->ends_at->getTimestamp() <= now()->getTimestamp()) {
                throw new ReferralException('An expired campaign window cannot be resumed — extend ends_at first.');
            }

            $this->assertNoActiveOverlap($locked);
        });
    }

    public function complete(ReferralCampaign $campaign, User $admin, string $reason): ReferralCampaign
    {
        return $this->transition($campaign, ReferralCampaignStatus::Completed, $admin, $reason, 'campaign_completed');
    }

    public function archive(ReferralCampaign $campaign, User $admin, string $reason): ReferralCampaign
    {
        return $this->transition($campaign, ReferralCampaignStatus::Archived, $admin, $reason, 'campaign_archived');
    }

    public function activeCampaignFor(DateTimeInterface $atUtc, ?int $countryId): ?ReferralCampaign
    {
        $query = ReferralCampaign::query()
            ->active()
            ->coveringInstant($atUtc);

        // A campaign with no country rows admits every country. A known
        // country also matches campaigns explicitly listing it; an
        // unknown country (null) only matches all-country campaigns.
        if ($countryId === null) {
            $query->whereDoesntHave('eligibleCountries');
        } else {
            $query->where(function ($q) use ($countryId): void {
                $q->whereDoesntHave('eligibleCountries')
                    ->orWhereHas('eligibleCountries', fn ($c) => $c->where('countries.id', $countryId));
            });
        }

        return $query
            ->orderBy('starts_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  callable(ReferralCampaign): void|null  $guard  extra validation under the row lock
     */
    private function transition(
        ReferralCampaign $campaign,
        ReferralCampaignStatus $target,
        User $admin,
        string $reason,
        string $auditEvent,
        ?callable $guard = null,
    ): ReferralCampaign {
        $this->assertCanManage($admin);

        if (trim($reason) === '') {
            throw new ReferralException('A campaign status change requires a reason.');
        }

        $campaign = DB::transaction(function () use ($campaign, $target, $admin, $guard): ReferralCampaign {
            $campaign = $this->locked($campaign);

            if (! $campaign->status->canTransitionTo($target)) {
                throw new ReferralException(sprintf(
                    'A %s campaign cannot become %s.',
                    $campaign->status->value,
                    $target->value,
                ));
            }

            if ($guard !== null) {
                $guard($campaign);
            }

            $campaign->forceFill([
                'status' => $target,
                'updated_by' => $admin->id,
            ])->save();

            return $campaign;
        });

        $this->auditTrail->logUser(
            $admin,
            self::LOG_NAME,
            $auditEvent,
            sprintf('Referral campaign "%s" is now %s.', $campaign->name, $campaign->status->value),
            $campaign,
            [...$this->safeMeta($campaign), 'reason' => $reason],
        );

        return $campaign;
    }

    private function assertValidRules(ReferralCampaignData $data): void
    {
        if (trim($data->name) === '') {
            throw new ReferralException('A campaign requires a name.');
        }

        if ($data->startsAt >= $data->endsAt) {
            throw new ReferralException('The campaign start must be before its end.');
        }

        if ($data->rewardValue <= 0) {
            throw new ReferralException('The reward value must be positive.');
        }

        if ($data->rewardType === ReferralRewardType::Percentage) {
            if ($data->rewardValue > self::MAX_PERCENTAGE_BASIS_POINTS) {
                throw new ReferralException('A percentage reward cannot exceed 10000 basis points (100%).');
            }

            if ($data->rewardCurrencyCode !== null) {
                throw new ReferralException('A percentage reward carries no currency — it follows the eligible lesson currency.');
            }
        }

        if ($data->rewardType === ReferralRewardType::Fixed) {
            if ($data->rewardCurrencyCode === null || trim($data->rewardCurrencyCode) === '') {
                throw new ReferralException('A fixed reward requires a currency.');
            }

            if (Currency::query()->active()->where('code', $data->rewardCurrencyCode)->doesntExist()) {
                throw new ReferralException(sprintf('Currency "%s" is not active.', $data->rewardCurrencyCode));
            }
        }

        if ($data->minCompletedPaidLessons < 1) {
            throw new ReferralException('The minimum completed paid lessons must be at least 1.');
        }

        if ($data->maxRewardedClasses < 1) {
            throw new ReferralException('The maximum rewarded classes must be at least 1.');
        }

        if ($data->rewardTiming === ReferralRewardTiming::Immediate && $data->holdDays !== 0) {
            throw new ReferralException('Immediate reward timing requires zero hold days.');
        }

        if ($data->rewardTiming === ReferralRewardTiming::AfterHoldDays && $data->holdDays < 1) {
            throw new ReferralException('Hold-based reward timing requires at least one hold day.');
        }
    }

    /**
     * Phase 19D — once ANY reward row references this campaign, its
     * reward-affecting rules are frozen forever: rewards snapshot the
     * rules they were calculated under, and history must stay
     * explainable by the campaign row it points at. Name, description
     * and terms remain editable; lifecycle transitions (pause/complete/
     * archive) remain governed by the status matrix, not by this.
     */
    private function assertRewardRulesStillMutable(ReferralCampaign $campaign, ReferralCampaignData $data): void
    {
        if (ReferralReward::query()->where('campaign_id', $campaign->id)->doesntExist()) {
            return;
        }

        $currentCountryIds = $campaign->eligibleCountries()->pluck('countries.id')->sort()->values()->all();
        $proposedCountryIds = collect($data->eligibleCountryIds)->map(fn ($id) => (int) $id)->sort()->values()->all();

        $changed = $campaign->reward_type !== $data->rewardType
            || $campaign->reward_value !== $data->rewardValue
            || $campaign->reward_currency_code !== $data->rewardCurrencyCode
            || $campaign->min_completed_paid_lessons !== $data->minCompletedPaidLessons
            || $campaign->max_rewarded_classes !== $data->maxRewardedClasses
            || $campaign->reward_timing !== $data->rewardTiming
            || $campaign->hold_days !== $data->holdDays
            || $campaign->requires_fraud_review !== $data->requiresFraudReview
            || $campaign->starts_at->notEqualTo($data->startsAt)
            || $campaign->ends_at->notEqualTo($data->endsAt)
            || $currentCountryIds !== $proposedCountryIds;

        if ($changed) {
            throw new ReferralException(
                'This campaign has already generated rewards — its reward rules, window, countries and fraud settings are permanently frozen. Only the name, description and terms may change; create a successor campaign for new rules.',
            );
        }
    }

    /**
     * Overlap safety rule: two ACTIVE campaigns may never cover the
     * same instant for an intersecting country scope. Scopes intersect
     * when either campaign is all-countries or they share a country.
     */
    private function assertNoActiveOverlap(ReferralCampaign $campaign): void
    {
        $candidates = ReferralCampaign::query()
            ->active()
            ->whereKeyNot($campaign->id)
            ->where('starts_at', '<', $campaign->ends_at)
            ->where('ends_at', '>', $campaign->starts_at)
            ->with('eligibleCountries:id')
            ->get();

        if ($candidates->isEmpty()) {
            return;
        }

        $ownCountryIds = $campaign->eligibleCountries()->pluck('countries.id')->all();

        foreach ($candidates as $other) {
            $otherCountryIds = $other->eligibleCountries->pluck('id')->all();

            $scopesIntersect = $ownCountryIds === []
                || $otherCountryIds === []
                || array_intersect($ownCountryIds, $otherCountryIds) !== [];

            if ($scopesIntersect) {
                throw new ReferralException(sprintf(
                    'Campaign window overlaps active campaign "%s" for an intersecting country scope — adjust the window or countries first.',
                    $other->name,
                ));
            }
        }
    }

    private function attributesFrom(ReferralCampaignData $data): array
    {
        $currency = $data->rewardCurrencyCode !== null
            ? Currency::query()->where('code', $data->rewardCurrencyCode)->first()
            : null;

        return [
            'name' => $data->name,
            'description' => $data->description,
            'starts_at' => $data->startsAt,
            'ends_at' => $data->endsAt,
            'reward_type' => $data->rewardType,
            'reward_value' => $data->rewardValue,
            'reward_currency_id' => $currency?->id,
            'reward_currency_code' => $currency?->code,
            'min_completed_paid_lessons' => $data->minCompletedPaidLessons,
            'max_rewarded_classes' => $data->maxRewardedClasses,
            'reward_timing' => $data->rewardTiming,
            'hold_days' => $data->holdDays,
            'requires_fraud_review' => $data->requiresFraudReview,
            'terms' => $data->terms,
        ];
    }

    /** Rule snapshot for audit rows — no terms body, no admin payload. */
    private function safeMeta(ReferralCampaign $campaign): array
    {
        return [
            'status' => $campaign->status->value,
            'starts_at' => $campaign->starts_at->toIso8601String(),
            'ends_at' => $campaign->ends_at->toIso8601String(),
            'reward_type' => $campaign->reward_type->value,
            'reward_value' => $campaign->reward_value,
            'reward_currency_code' => $campaign->reward_currency_code,
            'min_completed_paid_lessons' => $campaign->min_completed_paid_lessons,
            'max_rewarded_classes' => $campaign->max_rewarded_classes,
            'reward_timing' => $campaign->reward_timing->value,
            'hold_days' => $campaign->hold_days,
            'requires_fraud_review' => $campaign->requires_fraud_review,
            'eligible_country_ids' => $campaign->eligibleCountries()->pluck('countries.id')->all(),
        ];
    }

    private function locked(ReferralCampaign $campaign): ReferralCampaign
    {
        return ReferralCampaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
    }

    private function assertCanManage(User $admin): void
    {
        if (! $admin->can('ManageReferralCampaigns')) {
            throw new AuthorizationException;
        }
    }
}
