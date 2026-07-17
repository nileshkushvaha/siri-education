<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Models\Country;
use App\Models\Currency;
use App\Models\ReferralCampaign;
use App\Models\ReferralReward;
use App\Models\User;
use App\Referral\Contracts\ReferralCampaignServiceInterface;
use App\Referral\DTOs\ReferralCampaignData;
use App\Referral\Enums\ReferralRewardTiming;
use App\Referral\Enums\ReferralRewardType;
use App\Referral\Exceptions\ReferralException;
use Database\Seeders\ReferralPermissionSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 19D: once any reward references a campaign, every reward-
 * affecting rule is frozen — enforced in ReferralCampaignService, not
 * only Filament.
 */
class ReferralCampaignImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private ReferralCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferralPermissionSeeder::class);

        Currency::query()->firstOrCreate(['code' => 'INR'], [
            'name' => 'Indian Rupee', 'symbol' => 'Rs', 'numeric_code' => '356',
            'minor_units' => 2, 'status' => 'active', 'sort_order' => 1,
        ]);

        $this->manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->manager->assignRole(Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']));

        // A Paused (editable-status) campaign that has generated a reward.
        $this->campaign = ReferralCampaign::factory()->create([
            'status' => 'paused',
            'starts_at' => now()->subDay()->startOfSecond(),
            'ends_at' => now()->addDays(30)->startOfSecond(),
        ]);

        ReferralReward::factory()->create(['campaign_id' => $this->campaign->id]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function data(array $overrides = []): ReferralCampaignData
    {
        $defaults = [
            'name' => $this->campaign->name,
            'description' => $this->campaign->description,
            'startsAt' => DateTimeImmutable::createFromInterface($this->campaign->starts_at),
            'endsAt' => DateTimeImmutable::createFromInterface($this->campaign->ends_at),
            'rewardType' => $this->campaign->reward_type,
            'rewardValue' => $this->campaign->reward_value,
            'rewardCurrencyCode' => $this->campaign->reward_currency_code,
            'minCompletedPaidLessons' => $this->campaign->min_completed_paid_lessons,
            'maxRewardedClasses' => $this->campaign->max_rewarded_classes,
            'rewardTiming' => $this->campaign->reward_timing,
            'holdDays' => $this->campaign->hold_days,
            'requiresFraudReview' => $this->campaign->requires_fraud_review,
            'terms' => $this->campaign->terms,
            'eligibleCountryIds' => [],
        ];

        return new ReferralCampaignData(...array_merge($defaults, $overrides));
    }

    public function test_every_locked_rule_field_is_frozen_after_first_reward(): void
    {
        $country = Country::query()->create(['name' => 'India', 'iso2' => 'IN']);
        $service = app(ReferralCampaignServiceInterface::class);

        $lockedChanges = [
            'reward type' => ['rewardType' => ReferralRewardType::Fixed, 'rewardCurrencyCode' => 'INR', 'rewardValue' => 10000],
            'reward value' => ['rewardValue' => 900],
            'min lessons' => ['minCompletedPaidLessons' => 3],
            'max classes' => ['maxRewardedClasses' => 5],
            'timing + hold' => ['rewardTiming' => ReferralRewardTiming::AfterHoldDays, 'holdDays' => 7],
            'fraud flag' => ['requiresFraudReview' => true],
            'window start' => ['startsAt' => new DateTimeImmutable('-3 days')],
            'window end' => ['endsAt' => new DateTimeImmutable('+60 days')],
            'country scope' => ['eligibleCountryIds' => [$country->id]],
        ];

        foreach ($lockedChanges as $label => $overrides) {
            try {
                $service->update($this->campaign->refresh(), $this->data($overrides), $this->manager);
                $this->fail("Locked field change must be rejected: {$label}");
            } catch (ReferralException $e) {
                $this->assertStringContainsString('frozen', $e->getMessage(), $label);
            }
        }
    }

    public function test_name_description_and_terms_remain_editable_and_audited(): void
    {
        $service = app(ReferralCampaignServiceInterface::class);

        $updated = $service->update($this->campaign, $this->data([
            'name' => 'Renamed campaign',
            'description' => 'New copy.',
            'terms' => 'Clarified terms.',
        ]), $this->manager);

        $this->assertSame('Renamed campaign', $updated->name);
        $this->assertSame('Clarified terms.', $updated->terms);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'referral_campaigns', 'event' => 'campaign_rules_updated']);
    }

    public function test_lifecycle_transitions_remain_available_after_rewards_exist(): void
    {
        $service = app(ReferralCampaignServiceInterface::class);

        $resumed = $service->resume($this->campaign, $this->manager, 'Back on.');
        $paused = $service->pause($resumed, $this->manager, 'Off again.');
        $completed = $service->complete($paused, $this->manager, 'Done.');
        $archived = $service->archive($completed, $this->manager, 'Shelved.');

        $this->assertSame('archived', $archived->status->value);
    }

    public function test_a_campaign_without_rewards_remains_fully_editable(): void
    {
        $fresh = ReferralCampaign::factory()->create(['status' => 'draft']);
        $service = app(ReferralCampaignServiceInterface::class);

        $data = new ReferralCampaignData(
            name: 'Fresh rules',
            description: null,
            startsAt: new DateTimeImmutable('+1 day'),
            endsAt: new DateTimeImmutable('+20 days'),
            rewardType: ReferralRewardType::Percentage,
            rewardValue: 750,
            rewardCurrencyCode: null,
            minCompletedPaidLessons: 2,
            maxRewardedClasses: 4,
            rewardTiming: ReferralRewardTiming::Immediate,
            holdDays: 0,
            requiresFraudReview: false,
            terms: null,
        );

        $this->assertSame(750, $service->update($fresh, $data, $this->manager)->reward_value);
    }
}
