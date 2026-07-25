<?php

declare(strict_types=1);

namespace Tests\Feature\PromotionalCredits;

use App\PromotionalCredits\Services\PromotionalCreditService;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\Repositories\WalletFinancialReportRepository;
use App\Reporting\ValueObjects\ReportingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\PromotionalCredits\Concerns\CreatesPromotionalCreditFixtures;
use Tests\TestCase;

/**
 * Requirement #9: promotional-credit rows naturally belong in the
 * existing wallet financial report — verified here, not a speculative
 * new analytics module.
 */
class PromotionalCreditReportingTest extends TestCase
{
    use CreatesPromotionalCreditFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensurePromotionalCreditRoles();
    }

    public function test_promotional_credits_appear_in_the_existing_wallet_movements_report(): void
    {
        $admin = $this->fullAdmin();
        $student = $this->student();

        app(PromotionalCreditService::class)->issueManualCredit($student, $admin, 15000, 'INR', 'Reason.', 'promo_credit:'.Str::uuid());

        $period = ReportingPeriod::custom(CarbonImmutable::now()->subDay(), CarbonImmutable::now()->addDay());
        $movements = app(WalletFinancialReportRepository::class)->movements($period, new ReportFilters(period: $period));

        $promotionalRow = collect($movements)->firstWhere('entryType', 'promotional_credit');

        $this->assertNotNull($promotionalRow);
        $this->assertSame(15000, $promotionalRow['amountMinor']);
    }

    public function test_campaign_usage_summary_is_a_bounded_aggregate(): void
    {
        $admin = $this->fullAdmin();
        $campaign = $this->activeCampaign(['amount_minor' => 20000, 'total_budget_minor' => 100000, 'per_student_limit' => 5]);
        $service = app(PromotionalCreditService::class);

        $service->issueCampaignCredit($campaign, $this->student(), $admin, 'Award 1.', 'promo_credit:'.Str::uuid());
        $service->issueCampaignCredit($campaign, $this->student(), $admin, 'Award 2.', 'promo_credit:'.Str::uuid());

        $summary = $service->campaignUsageSummary($campaign);

        $this->assertSame(2, $summary['issued_count']);
        $this->assertSame(40000, $summary['issued_amount_minor']);
        $this->assertSame(60000, $summary['budget_remaining_minor']);
    }

    public function test_promotional_credit_campaign_summary_repository_method_is_bounded_and_correct(): void
    {
        $admin = $this->fullAdmin();
        $campaign = $this->activeCampaign(['amount_minor' => 20000, 'per_student_limit' => 5]);
        $service = app(PromotionalCreditService::class);

        $service->issueCampaignCredit($campaign, $this->student(), $admin, 'Award 1.', 'promo_credit:'.Str::uuid());
        $service->issueCampaignCredit($campaign, $this->student(), $admin, 'Award 2.', 'promo_credit:'.Str::uuid());

        $summary = app(WalletFinancialReportRepository::class)->promotionalCreditCampaignSummary();
        $row = collect($summary)->firstWhere('campaignId', $campaign->id);

        $this->assertNotNull($row);
        $this->assertSame(2, $row['issuedCount']);
        $this->assertSame(40000, $row['issuedAmountMinor']);
    }
}
