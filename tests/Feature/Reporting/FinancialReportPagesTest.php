<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Filament\Pages\FinanceOverview;
use App\Filament\Pages\InstructorFinancials;
use App\Filament\Pages\PaymentsReconciliation;
use App\Filament\Pages\ReportingHub;
use App\Filament\Pages\WalletRefunds;
use App\Models\User;
use App\Models\Wallet;
use App\Reporting\Contracts\ReportRegistryInterface;
use Database\Seeders\ReportingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Phase 18E §10/§22 — the four financial report pages: gates, no-secret rendering, registry integration. */
class FinancialReportPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReportingPermissionSeeder::class);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
    }

    private function manager(): User
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('manager');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin;
    }

    public function test_manager_can_open_all_four_pages(): void
    {
        $admin = $this->manager();

        foreach ([FinanceOverview::class, WalletRefunds::class, PaymentsReconciliation::class, InstructorFinancials::class] as $page) {
            $this->actingAs($admin)->get($page::getUrl())
                ->assertOk()
                ->assertSee('Reporting timezone')
                ->assertSee('Live query');
        }
    }

    public function test_non_admin_is_denied_on_all_pages(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        foreach ([FinanceOverview::class, WalletRefunds::class, PaymentsReconciliation::class, InstructorFinancials::class] as $page) {
            $this->actingAs($user)->get($page::getUrl())->assertForbidden();
            $this->assertFalse($page::canAccess());
        }
    }

    public function test_compensation_page_denied_without_its_specific_permission(): void
    {
        $admin = $this->manager();
        Role::findByName('manager', 'web')->revokePermissionTo('ViewInstructorCompensationReports');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($admin)->get(InstructorFinancials::getUrl())->assertForbidden();
        // General finance overview remains reachable — with the compensation section withheld.
        $this->actingAs($admin)->get(FinanceOverview::getUrl())
            ->assertOk()
            ->assertSee('Instructor compensation details require the instructor-compensation report permission.');
    }

    public function test_overview_withholds_wallet_and_payment_sections_without_their_permissions(): void
    {
        $admin = $this->manager();
        Role::findByName('manager', 'web')->revokePermissionTo(['ViewWalletReports', 'ViewPaymentReports']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($admin)->get(FinanceOverview::getUrl())
            ->assertOk()
            ->assertSee('Wallet details require the wallet-report permission.')
            ->assertSee('Payment collection details require the payment-report permission.');
    }

    public function test_pages_render_no_secret_material(): void
    {
        $admin = $this->manager();
        Wallet::factory()->create(['balance_minor' => 50000, 'available_balance_minor' => 50000]);

        foreach ([FinanceOverview::class, WalletRefunds::class, PaymentsReconciliation::class, InstructorFinancials::class] as $page) {
            $this->actingAs($admin)->get($page::getUrl())
                ->assertOk()
                ->assertDontSee('api_key')
                ->assertDontSee('webhook_secret')
                ->assertDontSee('key_secret')
                ->assertDontSee('encrypted_payload');
        }
    }

    public function test_no_revenue_label_is_rendered_on_financial_pages(): void
    {
        $admin = $this->manager();

        // §7 Outcome B — the finance pages never present a "Revenue" total.
        // ("not revenue" appears as an explicit disclaimer, so match the card label form.)
        $this->actingAs($admin)->get(FinanceOverview::getUrl())
            ->assertOk()
            ->assertDontSee('Total revenue')
            ->assertDontSee('Recognized revenue')
            ->assertDontSee('Net revenue')
            ->assertDontSee('Platform margin');
    }

    public function test_livewire_string_filter_hydration_is_safe(): void
    {
        Livewire::actingAs($this->manager())
            ->test(WalletRefunds::class)
            ->set('currencyCode', 'inr')
            ->set('walletTransactionType', 'refund')
            ->set('walletTransactionType', '')
            ->set('periodPreset', 'custom')
            ->set('customStart', '')
            ->set('customEnd', '')
            ->assertOk();
    }

    public function test_all_five_financial_reports_registered_available_with_real_routes(): void
    {
        $registry = app(ReportRegistryInterface::class);

        foreach ([
            'finance_overview' => FinanceOverview::class,
            'wallet_activity' => WalletRefunds::class,
            'refund_report' => WalletRefunds::class,
            'payment_outcomes' => PaymentsReconciliation::class,
            'earnings_settlements' => InstructorFinancials::class,
        ] as $key => $page) {
            $definition = $registry->find($key);
            $this->assertNotNull($definition, $key);
            $this->assertTrue($definition->available, $key);
            $this->assertTrue($definition->financial, $key);
            $this->assertSame($page, $definition->routeName, $key);
        }
    }

    public function test_reporting_hub_lists_the_financial_reports(): void
    {
        $this->actingAs($this->manager())
            ->get(ReportingHub::getUrl())
            ->assertOk()
            ->assertSee('Finance Overview')
            ->assertSee('Wallet Activity')
            ->assertSee('Payment Outcomes')
            ->assertSee('Earnings &amp; Settlements', false);
    }
}
