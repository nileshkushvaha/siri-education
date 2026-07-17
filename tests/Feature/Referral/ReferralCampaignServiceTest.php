<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Exceptions\HistoricalRecordCannotBeDeletedException;
use App\Models\Activity;
use App\Models\Country;
use App\Models\Currency;
use App\Models\ReferralCampaign;
use App\Models\User;
use App\Referral\Contracts\ReferralCampaignServiceInterface;
use App\Referral\DTOs\ReferralCampaignData;
use App\Referral\Enums\ReferralCampaignStatus;
use App\Referral\Enums\ReferralRewardTiming;
use App\Referral\Enums\ReferralRewardType;
use App\Referral\Exceptions\ReferralException;
use Database\Seeders\ReferralPermissionSeeder;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReferralCampaignServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

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
    }

    private function service(): ReferralCampaignServiceInterface
    {
        return app(ReferralCampaignServiceInterface::class);
    }

    /** @param  array<string, mixed>  $overrides */
    private function data(array $overrides = []): ReferralCampaignData
    {
        $defaults = [
            'name' => 'Launch Referral',
            'description' => null,
            'startsAt' => new DateTimeImmutable('+1 day'),
            'endsAt' => new DateTimeImmutable('+30 days'),
            'rewardType' => ReferralRewardType::Percentage,
            'rewardValue' => 500,
            'rewardCurrencyCode' => null,
            'minCompletedPaidLessons' => 1,
            'maxRewardedClasses' => 10,
            'rewardTiming' => ReferralRewardTiming::Immediate,
            'holdDays' => 0,
            'requiresFraudReview' => false,
            'terms' => 'Standard terms.',
            'eligibleCountryIds' => [],
        ];

        return new ReferralCampaignData(...array_merge($defaults, $overrides));
    }

    // ── Creation & validation ─────────────────────────────────────────────

    public function test_create_produces_an_audited_draft_with_countries(): void
    {
        $countryIds = [
            Country::query()->create(['name' => 'India', 'iso2' => 'IN'])->id,
            Country::query()->create(['name' => 'United Kingdom', 'iso2' => 'GB'])->id,
        ];

        $campaign = $this->service()->create($this->data(['eligibleCountryIds' => $countryIds]), $this->manager);

        $this->assertSame(ReferralCampaignStatus::Draft, $campaign->status);
        $this->assertSame(500, $campaign->reward_value);
        $this->assertNull($campaign->reward_currency_code);
        $this->assertEqualsCanonicalizing($countryIds, $campaign->eligibleCountries()->pluck('countries.id')->all());

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'referral_campaigns',
            'event' => 'campaign_created',
        ]);
    }

    public function test_create_requires_the_manage_permission(): void
    {
        $unauthorized = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $this->expectException(AuthorizationException::class);

        $this->service()->create($this->data(), $unauthorized);
    }

    public function test_invalid_rules_are_rejected(): void
    {
        $cases = [
            'start after end' => ['startsAt' => new DateTimeImmutable('+30 days'), 'endsAt' => new DateTimeImmutable('+1 day')],
            'zero reward' => ['rewardValue' => 0],
            'percentage above 100%' => ['rewardValue' => 10001],
            'percentage with currency' => ['rewardCurrencyCode' => 'INR'],
            'fixed without currency' => ['rewardType' => ReferralRewardType::Fixed, 'rewardValue' => 10000, 'rewardCurrencyCode' => null],
            'fixed with unknown currency' => ['rewardType' => ReferralRewardType::Fixed, 'rewardValue' => 10000, 'rewardCurrencyCode' => 'ZZZ'],
            'min lessons below one' => ['minCompletedPaidLessons' => 0],
            'max classes below one' => ['maxRewardedClasses' => 0],
            'immediate with hold days' => ['holdDays' => 3],
            'hold timing without days' => ['rewardTiming' => ReferralRewardTiming::AfterHoldDays, 'holdDays' => 0],
        ];

        foreach ($cases as $label => $overrides) {
            try {
                $this->service()->create($this->data($overrides), $this->manager);
                $this->fail("Expected ReferralException for case: {$label}");
            } catch (ReferralException) {
                // expected
            }
        }

        $this->assertSame(0, ReferralCampaign::query()->count());
    }

    public function test_db_check_constraints_are_the_final_guard(): void
    {
        $this->expectException(QueryException::class);

        ReferralCampaign::factory()->create([
            'reward_type' => ReferralRewardType::Percentage,
            'reward_value' => 20000,
        ]);
    }

    public function test_campaigns_can_never_be_hard_deleted(): void
    {
        $campaign = ReferralCampaign::factory()->create();

        $this->expectException(HistoricalRecordCannotBeDeletedException::class);

        $campaign->delete();
    }

    // ── Rule edits ────────────────────────────────────────────────────────

    public function test_update_is_audited_with_before_and_after_and_limited_to_editable_statuses(): void
    {
        $campaign = $this->service()->create($this->data(), $this->manager);

        $updated = $this->service()->update($campaign, $this->data(['rewardValue' => 750]), $this->manager);

        $this->assertSame(750, $updated->reward_value);

        $log = Activity::query()
            ->where('log_name', 'referral_campaigns')
            ->where('event', 'campaign_rules_updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame(500, $log->properties['before']['reward_value']);
        $this->assertSame(750, $log->properties['after']['reward_value']);

        $this->service()->activate($updated, $this->manager, 'Go live.');

        $this->expectException(ReferralException::class);

        $this->service()->update($updated->refresh(), $this->data(['rewardValue' => 900]), $this->manager);
    }

    // ── Lifecycle ─────────────────────────────────────────────────────────

    public function test_the_full_valid_lifecycle_path_is_transitionable_and_audited(): void
    {
        $campaign = $this->service()->create($this->data(), $this->manager);

        $campaign = $this->service()->activate($campaign, $this->manager, 'Launch.');
        $this->assertSame(ReferralCampaignStatus::Active, $campaign->status);

        $campaign = $this->service()->pause($campaign, $this->manager, 'Investigating volumes.');
        $this->assertSame(ReferralCampaignStatus::Paused, $campaign->status);

        $campaign = $this->service()->resume($campaign, $this->manager, 'All clear.');
        $this->assertSame(ReferralCampaignStatus::Active, $campaign->status);

        $campaign = $this->service()->complete($campaign, $this->manager, 'Window done.');
        $this->assertSame(ReferralCampaignStatus::Completed, $campaign->status);

        $campaign = $this->service()->archive($campaign, $this->manager, 'Bookkeeping.');
        $this->assertSame(ReferralCampaignStatus::Archived, $campaign->status);

        foreach (['campaign_activated', 'campaign_paused', 'campaign_resumed', 'campaign_completed', 'campaign_archived'] as $event) {
            $this->assertSame(1, Activity::query()
                ->where('log_name', 'referral_campaigns')
                ->where('event', $event)
                ->count(), "Expected exactly one '{$event}' audit row.");
        }
    }

    public function test_invalid_transitions_are_rejected(): void
    {
        $draft = $this->service()->create($this->data(), $this->manager);

        // Draft cannot pause or complete.
        foreach (['pause', 'complete'] as $method) {
            try {
                $this->service()->{$method}($draft, $this->manager, 'Nope.');
                $this->fail("Draft must not {$method}.");
            } catch (ReferralException) {
                // expected
            }
        }

        // Completed can never become active again.
        $campaign = $this->service()->create($this->data(['name' => 'Second']), $this->manager);
        $campaign = $this->service()->activate($campaign, $this->manager, 'Launch.');
        $campaign = $this->service()->complete($campaign, $this->manager, 'Done.');

        try {
            $this->service()->activate($campaign, $this->manager, 'Zombie.');
            $this->fail('Completed must not reactivate.');
        } catch (ReferralException) {
            // expected
        }

        try {
            $this->service()->resume($campaign, $this->manager, 'Zombie.');
            $this->fail('Completed must not resume.');
        } catch (ReferralException) {
            // expected
        }

        // Archived is terminal.
        $campaign = $this->service()->archive($campaign, $this->manager, 'Shelve.');

        foreach (['activate', 'pause', 'complete'] as $method) {
            try {
                $this->service()->{$method}($campaign, $this->manager, 'Nope.');
                $this->fail("Archived must not {$method}.");
            } catch (ReferralException) {
                // expected
            }
        }

        $this->assertSame(ReferralCampaignStatus::Archived, $campaign->refresh()->status);
    }

    public function test_transitions_require_a_reason_and_the_manage_permission(): void
    {
        $campaign = $this->service()->create($this->data(), $this->manager);

        try {
            $this->service()->activate($campaign, $this->manager, '   ');
            $this->fail('Expected a ReferralException for the blank reason.');
        } catch (ReferralException) {
            // expected
        }

        $unauthorized = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $this->expectException(AuthorizationException::class);

        $this->service()->activate($campaign, $unauthorized, 'Trying anyway.');
    }

    public function test_an_expired_window_cannot_be_activated(): void
    {
        $campaign = ReferralCampaign::factory()->create([
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->subDay(),
        ]);

        $this->expectException(ReferralException::class);
        $this->expectExceptionMessageMatches('/expired campaign window/');

        $this->service()->activate($campaign, $this->manager, 'Too late.');
    }

    // ── Overlap safety rule ───────────────────────────────────────────────

    public function test_overlapping_active_windows_with_intersecting_scope_are_prevented(): void
    {
        $first = $this->service()->create($this->data(['name' => 'First']), $this->manager);
        $this->service()->activate($first, $this->manager, 'Launch.');

        // Same window, both all-countries — must be refused.
        $second = $this->service()->create($this->data(['name' => 'Second']), $this->manager);

        try {
            $this->service()->activate($second, $this->manager, 'Clash.');
            $this->fail('Overlapping all-country campaigns must not co-activate.');
        } catch (ReferralException $e) {
            $this->assertStringContainsString('overlaps active campaign', $e->getMessage());
        }

        $this->assertSame(ReferralCampaignStatus::Draft, $second->refresh()->status);
    }

    public function test_disjoint_country_scopes_may_overlap_in_time(): void
    {
        $india = Country::query()->create(['name' => 'India', 'iso2' => 'IN']);
        $uk = Country::query()->create(['name' => 'United Kingdom', 'iso2' => 'GB']);

        $first = $this->service()->create($this->data(['name' => 'India campaign', 'eligibleCountryIds' => [$india->id]]), $this->manager);
        $this->service()->activate($first, $this->manager, 'Launch IN.');

        $second = $this->service()->create($this->data(['name' => 'UK campaign', 'eligibleCountryIds' => [$uk->id]]), $this->manager);
        $second = $this->service()->activate($second, $this->manager, 'Launch GB.');

        $this->assertSame(ReferralCampaignStatus::Active, $second->status);

        // A shared country makes the scopes intersect again.
        $third = $this->service()->create($this->data(['name' => 'IN+GB campaign', 'eligibleCountryIds' => [$india->id, $uk->id]]), $this->manager);

        $this->expectException(ReferralException::class);

        $this->service()->activate($third, $this->manager, 'Clash.');
    }

    public function test_adjacent_half_open_windows_do_not_overlap(): void
    {
        $boundary = now()->addDays(10)->startOfHour()->toImmutable();

        $first = $this->service()->create($this->data([
            'name' => 'Early',
            'startsAt' => new DateTimeImmutable('+1 day'),
            'endsAt' => DateTimeImmutable::createFromInterface($boundary),
        ]), $this->manager);
        $this->service()->activate($first, $this->manager, 'Launch.');

        $second = $this->service()->create($this->data([
            'name' => 'Late',
            'startsAt' => DateTimeImmutable::createFromInterface($boundary),
            'endsAt' => new DateTimeImmutable('+40 days'),
        ]), $this->manager);

        $second = $this->service()->activate($second, $this->manager, 'Launch.');

        $this->assertSame(ReferralCampaignStatus::Active, $second->status);
    }
}
