<?php

declare(strict_types=1);

namespace Tests\Feature\Referral;

use App\Models\Country;
use App\Models\ReferralCampaign;
use App\Referral\Contracts\ReferralCampaignServiceInterface;
use App\Referral\Enums\ReferralCampaignStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * activeCampaignFor() is the read-only contract Phase 19D reward
 * evaluation will call. Campaign selection happens at reward-evaluation
 * time (SRS 16.11: "Referral campaign must be active at the time
 * eligibility is evaluated") — attribution rows deliberately carry no
 * campaign_id (Phase 19C linkage decision, Option B).
 */
class ReferralCampaignResolverTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): ReferralCampaignServiceInterface
    {
        return app(ReferralCampaignServiceInterface::class);
    }

    private function activeCampaign(array $overrides = []): ReferralCampaign
    {
        return ReferralCampaign::factory()->active()->create($overrides);
    }

    public function test_resolves_the_active_campaign_covering_the_instant(): void
    {
        $campaign = $this->activeCampaign([
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->addDays(5),
        ]);

        $this->assertSame($campaign->id, $this->resolver()->activeCampaignFor(now(), null)?->id);
    }

    public function test_half_open_window_boundaries(): void
    {
        $start = now()->addDays(2)->startOfHour()->toImmutable();
        $end = now()->addDays(9)->startOfHour()->toImmutable();

        $campaign = $this->activeCampaign(['starts_at' => $start, 'ends_at' => $end]);

        // Before start: no match. At start (inclusive): match.
        $this->assertNull($this->resolver()->activeCampaignFor($start->subSecond(), null));
        $this->assertSame($campaign->id, $this->resolver()->activeCampaignFor($start, null)?->id);

        // Just before end: match. At end (exclusive): no match.
        $this->assertSame($campaign->id, $this->resolver()->activeCampaignFor($end->subSecond(), null)?->id);
        $this->assertNull($this->resolver()->activeCampaignFor($end, null));
    }

    public function test_non_active_statuses_never_resolve(): void
    {
        foreach ([
            ReferralCampaignStatus::Draft,
            ReferralCampaignStatus::Paused,
            ReferralCampaignStatus::Completed,
            ReferralCampaignStatus::Archived,
        ] as $status) {
            ReferralCampaign::factory()->create([
                'status' => $status,
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addDay(),
            ]);
        }

        $this->assertNull($this->resolver()->activeCampaignFor(now(), null));
    }

    public function test_country_eligibility(): void
    {
        $india = Country::query()->create(['name' => 'India', 'iso2' => 'IN']);
        $uk = Country::query()->create(['name' => 'United Kingdom', 'iso2' => 'GB']);
        $us = Country::query()->create(['name' => 'United States', 'iso2' => 'US']);

        $campaign = $this->activeCampaign();
        $campaign->eligibleCountries()->sync([$india->id, $uk->id]);

        // Listed countries match; an unlisted country does not.
        $this->assertSame($campaign->id, $this->resolver()->activeCampaignFor(now(), $india->id)?->id);
        $this->assertSame($campaign->id, $this->resolver()->activeCampaignFor(now(), $uk->id)?->id);
        $this->assertNull($this->resolver()->activeCampaignFor(now(), $us->id));

        // A student with no country only matches all-country campaigns.
        $this->assertNull($this->resolver()->activeCampaignFor(now(), null));
    }

    public function test_all_country_campaign_admits_any_and_unknown_countries(): void
    {
        $india = Country::query()->create(['name' => 'India', 'iso2' => 'IN']);

        $campaign = $this->activeCampaign();

        $this->assertSame($campaign->id, $this->resolver()->activeCampaignFor(now(), $india->id)?->id);
        $this->assertSame($campaign->id, $this->resolver()->activeCampaignFor(now(), null)?->id);
    }

    public function test_no_matching_campaign_returns_null(): void
    {
        $this->assertNull($this->resolver()->activeCampaignFor(now(), null));
    }

    public function test_overlapping_data_resolves_deterministically_by_earliest_start_then_id(): void
    {
        // The service prevents this state, but bad data must still be
        // deterministic — never "first row returned".
        $later = $this->activeCampaign([
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->addDays(10),
        ]);
        $earlier = $this->activeCampaign([
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->addDays(10),
        ]);

        $this->assertSame($earlier->id, $this->resolver()->activeCampaignFor(now(), null)?->id);

        // Same start: the lowest id wins.
        $earlier->forceFill(['starts_at' => $later->starts_at])->save();

        $this->assertSame(
            min($later->id, $earlier->id),
            $this->resolver()->activeCampaignFor(now(), null)?->id,
        );
    }
}
