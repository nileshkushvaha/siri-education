<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Filament\Pages\ExecutiveKpiOverview;
use App\Filament\Pages\MarketplaceSupplyDemand;
use App\Filament\Pages\ReportingHub;
use App\Models\User;
use App\Reporting\Contracts\ReportRegistryInterface;
use Database\Seeders\ReportingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 18H — the two pages: independent permission gates, honest
 * labelling, permission-degraded executive sections, hydration safety
 * and registry/hub integration.
 */
class MarketplaceExecutivePagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReportingPermissionSeeder::class);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
    }

    private function manager(): User
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('manager');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin;
    }

    // ── Access ───────────────────────────────────────────────────────────

    public function test_manager_can_open_both_pages(): void
    {
        $admin = $this->manager();

        $this->actingAs($admin)->get(MarketplaceSupplyDemand::getUrl())
            ->assertOk()
            ->assertSee('Live query')
            ->assertSee('Supply figures are current-state');

        $this->actingAs($admin)->get(ExecutiveKpiOverview::getUrl())
            ->assertOk()
            ->assertSee('Live query')
            ->assertSee('this page adds no calculation of its own');
    }

    public function test_pages_gate_independently(): void
    {
        $admin = $this->manager();
        Role::findByName('manager', 'web')->revokePermissionTo('ViewMarketplaceReports');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($admin)->get(MarketplaceSupplyDemand::getUrl())->assertForbidden();
        $this->actingAs($admin)->get(ExecutiveKpiOverview::getUrl())->assertOk();
    }

    public function test_unpermissioned_user_is_denied_on_both(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($user)->get(MarketplaceSupplyDemand::getUrl())->assertForbidden();
        $this->actingAs($user)->get(ExecutiveKpiOverview::getUrl())->assertForbidden();
    }

    // ── Executive permission degradation ──────────────────────────────────

    public function test_executive_finance_sections_degrade_without_their_permissions(): void
    {
        $admin = $this->manager();
        foreach (['ViewPaymentReports', 'ViewWalletReports', 'ViewInstructorCompensationReports'] as $permission) {
            Role::findByName('manager', 'web')->revokePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($admin)->get(ExecutiveKpiOverview::getUrl())
            ->assertOk()
            ->assertSee('Payment figures require the payment-report permission.')
            ->assertSee('Wallet figures require the wallet-report permission.')
            ->assertSee('Instructor compensation requires its own dedicated permission.');
    }

    public function test_executive_states_no_revenue_and_absent_communication_sources(): void
    {
        $this->actingAs($this->manager())->get(ExecutiveKpiOverview::getUrl())
            ->assertOk()
            ->assertSee('No recognized revenue, net revenue or margin exists')
            ->assertSee('Delivery rate, provider performance and messaging analytics are unavailable');
    }

    public function test_marketplace_states_no_score_and_no_search_inference(): void
    {
        $this->actingAs($this->manager())->get(MarketplaceSupplyDemand::getUrl())
            ->assertOk()
            ->assertSee('no supply-demand score, no instructor ranking')
            ->assertSee('never a historical denominator');
    }

    // ── Hydration safety ─────────────────────────────────────────────────

    public function test_string_filter_hydration_never_throws(): void
    {
        $this->actingAs($this->manager());

        Livewire::test(MarketplaceSupplyDemand::class)
            ->set('countryId', '5')
            ->set('subjectId', 'not-a-uuid')
            ->set('periodPreset', 'last_7_days')
            ->call('resetFilters')
            ->assertSet('subjectId', null)
            ->assertOk();

        Livewire::test(ExecutiveKpiOverview::class)
            ->set('periodPreset', 'last_7_days')
            ->call('resetFilters')
            ->assertSet('periodPreset', 'this_month')
            ->assertOk();
    }

    // ── Registry & hub ────────────────────────────────────────────────────

    public function test_both_reports_are_available_with_real_routes(): void
    {
        $registry = app(ReportRegistryInterface::class);

        $marketplace = $registry->find('marketplace_supply_demand');
        $this->assertNotNull($marketplace);
        $this->assertTrue($marketplace->available);
        $this->assertSame('ViewMarketplaceReports', $marketplace->requiredViewPermission);
        $this->assertSame(MarketplaceSupplyDemand::class, $marketplace->routeName);

        $executive = $registry->find('executive_summary');
        $this->assertNotNull($executive);
        $this->assertTrue($executive->available);
        $this->assertSame('ViewExecutiveReports', $executive->requiredViewPermission);
        $this->assertSame(ExecutiveKpiOverview::class, $executive->routeName);
    }

    public function test_reporting_hub_lists_both_reports(): void
    {
        $this->actingAs($this->manager())->get(ReportingHub::getUrl())
            ->assertOk()
            ->assertSee('Marketplace Supply &amp; Demand', false)
            ->assertSee('Executive KPI Overview');
    }
}
