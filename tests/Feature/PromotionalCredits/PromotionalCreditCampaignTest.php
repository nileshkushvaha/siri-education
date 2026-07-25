<?php

declare(strict_types=1);

namespace Tests\Feature\PromotionalCredits;

use App\Models\Currency;
use App\Models\PromotionalCreditCampaign;
use App\PromotionalCredits\DTOs\PromotionalCreditCampaignData;
use App\PromotionalCredits\Enums\PromotionalCreditCampaignStatus;
use App\PromotionalCredits\Exceptions\PromotionalCreditException;
use App\PromotionalCredits\Services\PromotionalCreditService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\PromotionalCredits\Concerns\CreatesPromotionalCreditFixtures;
use Tests\TestCase;

/** GAP-041 / SRS §16.17-§16.19: campaign creation, rule edits, and lifecycle transitions. */
class PromotionalCreditCampaignTest extends TestCase
{
    use CreatesPromotionalCreditFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensurePromotionalCreditRoles();
    }

    private function campaignData(array $overrides = []): PromotionalCreditCampaignData
    {
        return new PromotionalCreditCampaignData(
            name: $overrides['name'] ?? 'Launch Bonus '.uniqid(),
            description: 'A launch bonus campaign.',
            startsAt: CarbonImmutable::now()->subDay()->toImmutable(),
            endsAt: CarbonImmutable::now()->addDays(30)->toImmutable(),
            amountMinor: $overrides['amountMinor'] ?? 50000,
            currencyCode: 'INR',
            perStudentLimit: $overrides['perStudentLimit'] ?? 1,
            totalBudgetMinor: $overrides['totalBudgetMinor'] ?? null,
            terms: null,
        );
    }

    public function test_an_authorized_admin_can_create_a_campaign(): void
    {
        $admin = $this->fullAdmin();

        $campaign = app(PromotionalCreditService::class)->createCampaign($this->campaignData(), $admin);

        $this->assertSame(PromotionalCreditCampaignStatus::Draft, $campaign->status);
        $this->assertSame(50000, $campaign->amount_minor);
        $this->assertSame('INR', $campaign->currency_code);
    }

    public function test_an_unauthorized_user_cannot_create_a_campaign(): void
    {
        $this->expectException(AuthorizationException::class);
        app(PromotionalCreditService::class)->createCampaign($this->campaignData(), $this->student());
    }

    public function test_a_campaign_can_be_activated_paused_resumed_completed_and_archived(): void
    {
        $admin = $this->fullAdmin();
        $service = app(PromotionalCreditService::class);
        $campaign = $service->createCampaign($this->campaignData(), $admin);

        $campaign = $service->activateCampaign($campaign, $admin, 'Launching now.');
        $this->assertSame(PromotionalCreditCampaignStatus::Active, $campaign->status);

        $campaign = $service->pauseCampaign($campaign, $admin, 'Pausing for review.');
        $this->assertSame(PromotionalCreditCampaignStatus::Paused, $campaign->status);

        $campaign = $service->resumeCampaign($campaign, $admin, 'Resuming.');
        $this->assertSame(PromotionalCreditCampaignStatus::Active, $campaign->status);

        $campaign = $service->completeCampaign($campaign, $admin, 'Campaign concluded.');
        $this->assertSame(PromotionalCreditCampaignStatus::Completed, $campaign->status);

        $campaign = $service->archiveCampaign($campaign, $admin, 'Archiving.');
        $this->assertSame(PromotionalCreditCampaignStatus::Archived, $campaign->status);
    }

    public function test_a_status_transition_requires_a_reason(): void
    {
        $admin = $this->fullAdmin();
        $campaign = app(PromotionalCreditService::class)->createCampaign($this->campaignData(), $admin);

        $this->expectException(PromotionalCreditException::class);
        app(PromotionalCreditService::class)->activateCampaign($campaign, $admin, '');
    }

    public function test_an_archived_campaign_can_never_be_reactivated(): void
    {
        $admin = $this->fullAdmin();
        $service = app(PromotionalCreditService::class);
        $campaign = $service->createCampaign($this->campaignData(), $admin);
        $campaign = $service->archiveCampaign($campaign, $admin, 'No longer needed.');

        $this->expectException(PromotionalCreditException::class);
        $service->activateCampaign($campaign, $admin, 'Trying to bring it back.');
    }

    public function test_an_active_campaign_cannot_be_edited(): void
    {
        $admin = $this->fullAdmin();
        $service = app(PromotionalCreditService::class);
        $campaign = $service->createCampaign($this->campaignData(), $admin);
        $campaign = $service->activateCampaign($campaign, $admin, 'Go live.');

        $this->expectException(PromotionalCreditException::class);
        $service->updateCampaign($campaign, $this->campaignData(['name' => $campaign->name]), $admin);
    }

    public function test_rules_are_frozen_once_a_campaign_has_issued_credits(): void
    {
        $admin = $this->fullAdmin();
        $service = app(PromotionalCreditService::class);
        $campaign = $this->activeCampaign();
        $student = $this->student();

        $service->issueCampaignCredit($campaign, $student, $admin, 'Launch bonus.', 'promo:test:'.uniqid());

        $campaign = $service->pauseCampaign($campaign, $admin, 'Pausing to edit.');

        $this->expectException(PromotionalCreditException::class);
        $service->updateCampaign($campaign, $this->campaignData(['name' => $campaign->name, 'amountMinor' => 99999]), $admin);
    }

    public function test_campaign_name_must_be_unique(): void
    {
        $admin = $this->fullAdmin();
        $service = app(PromotionalCreditService::class);
        $data = $this->campaignData(['name' => 'Unique Campaign Name']);
        $service->createCampaign($data, $admin);

        $this->expectException(QueryException::class);
        PromotionalCreditCampaign::query()->create([
            'name' => 'Unique Campaign Name',
            'status' => PromotionalCreditCampaignStatus::Draft,
            'starts_at' => now(),
            'ends_at' => now()->addDay(),
            'amount_minor' => 1000,
            'currency_id' => Currency::query()->where('code', 'INR')->sole()->id,
            'currency_code' => 'INR',
            'per_student_limit' => 1,
        ]);
    }
}
