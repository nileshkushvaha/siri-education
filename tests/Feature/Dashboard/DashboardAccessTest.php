<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Dashboard\DTOs\DashboardContext;
use App\Dashboard\DTOs\DashboardData;
use App\Dashboard\DTOs\DomainSummary;
use App\Dashboard\Services\AttentionFeedService;
use App\Dashboard\Services\DashboardCompositionService;
use App\Filament\Pages\Dashboard;
use App\Models\User;
use App\Reporting\Contracts\MarketplaceExecutiveReportServiceInterface;
use App\Reporting\DTOs\Marketplace\ExecutiveKpiOverviewData;
use App\Reporting\DTOs\Marketplace\MarketplaceComparisonData;
use App\Reporting\DTOs\Marketplace\MarketplaceDemandData;
use App\Reporting\DTOs\Marketplace\MarketplaceSupplyData;
use App\Reporting\DTOs\Operations\OperationsReportFreshnessData;
use App\Reporting\Enums\ReportingPeriodPreset;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\Support\ReportingTimezoneResolver;
use App\Reporting\ValueObjects\ReportingPeriod;
use Database\Seeders\ReportingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Access and security for the redesigned dashboard.
 *
 * The central guarantee under test is that an unauthorised section is
 * never QUERIED, not merely never rendered — the composition service
 * checks each permission before calling the owning report service, so
 * restricted data never enters the process.
 */
class DashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReportingPermissionSeeder::class);
    }

    // ── Portal access ────────────────────────────────────────────────

    public function test_manager_can_open_the_dashboard(): void
    {
        $this->actingAs($this->manager())
            ->get(Dashboard::getUrl())
            ->assertOk()
            ->assertSee('Needs attention');
    }

    public function test_super_admin_can_open_the_dashboard(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(Dashboard::getUrl())
            ->assertOk();
    }

    public function test_instructor_cannot_reach_the_admin_dashboard(): void
    {
        $instructor = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        $instructor->assignRole('instructor');

        // PortalResolver routes instructors to the frontend portal, so the
        // panel gate rejects them before any dashboard code runs.
        $this->actingAs($instructor)->get(Dashboard::getUrl())->assertForbidden();
    }

    public function test_student_cannot_reach_the_admin_dashboard(): void
    {
        $student = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $student->assignRole('student');

        $this->actingAs($student)->get(Dashboard::getUrl())->assertForbidden();
    }

    public function test_guest_is_redirected(): void
    {
        $this->get(Dashboard::getUrl())->assertRedirect();
    }

    // ── Sections are omitted, not hidden ─────────────────────────────

    public function test_user_without_any_reporting_permission_gets_no_business_sections(): void
    {
        $user = $this->adminWithoutReportingPermissions();

        $data = $this->compose($user);

        $this->assertSame([], $data->kpis);
        $this->assertSame([], $data->charts);
        $this->assertSame([], $data->summaries);
        $this->assertFalse($data->hasBusinessContent());
    }

    public function test_student_reporting_permission_alone_yields_only_student_sections(): void
    {
        $user = $this->adminWithoutReportingPermissions();
        $user->givePermissionTo('ViewStudentReports');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $keys = array_map(fn ($kpi): string => $kpi->key, $this->compose($user)->kpis);

        $this->assertContains('engaged_students', $keys);
        $this->assertContains('new_students', $keys);
        // Booking/lesson figures need their own permissions.
        $this->assertNotContains('bookings_scheduled', $keys);
        $this->assertNotContains('lessons_completed', $keys);
        $this->assertNotContains('active_instructors', $keys);
    }

    /**
     * The load-bearing assertion: a restricted domain's tables are never
     * touched. Hiding output would still have executed the query.
     */
    public function test_restricted_domains_are_never_queried(): void
    {
        $user = $this->adminWithoutReportingPermissions();
        $user->givePermissionTo('ViewStudentReports');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->compose($user);

        $queries = array_map(
            static fn (array $q): string => strtolower($q['query']),
            DB::getQueryLog(),
        );
        DB::disableQueryLog();

        $sql = implode(' | ', $queries);

        // No wallet, earnings or payment reporting query may appear for a
        // user holding only a student-reporting permission.
        $this->assertStringNotContainsString('from `wallets`', $sql);
        $this->assertStringNotContainsString('from `wallet_ledger_entries`', $sql);
        $this->assertStringNotContainsString('from `instructor_earnings`', $sql);
        $this->assertStringNotContainsString('from `instructor_settlement_batches`', $sql);
        $this->assertStringNotContainsString('from `booking_payments`', $sql);
    }

    // ── Instructor compensation stays strictly separate ──────────────

    public function test_finance_permission_alone_does_not_expose_instructor_compensation(): void
    {
        $user = $this->adminWithoutReportingPermissions();
        // Deliberately grants general finance + wallet + payments, but NOT
        // ViewInstructorCompensationReports.
        $user->givePermissionTo(['ViewFinanceReports', 'ViewWalletReports', 'ViewPaymentReports']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $money = $this->summary($this->compose($user), 'money');

        $this->assertNotNull($money, 'A finance-permitted user should still see a money summary.');

        $labels = array_map(fn ($metric): string => $metric->label, $money->metrics);

        $this->assertNotContains('Instructor earning liability (releasable)', $labels);
    }

    public function test_compensation_permission_exposes_instructor_earning_liability(): void
    {
        $user = $this->adminWithoutReportingPermissions();
        $user->givePermissionTo([
            'ViewFinanceReports', 'ViewWalletReports', 'ViewPaymentReports',
            'ViewInstructorCompensationReports',
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $money = $this->summary($this->compose($user), 'money');
        $labels = array_map(fn ($metric): string => $metric->label, $money->metrics);

        $this->assertContains('Instructor earning liability (releasable)', $labels);
    }

    public function test_instructor_earnings_table_is_not_queried_without_compensation_permission(): void
    {
        $user = $this->adminWithoutReportingPermissions();
        $user->givePermissionTo(['ViewFinanceReports', 'ViewWalletReports', 'ViewPaymentReports']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->compose($user);

        $sql = strtolower(implode(' | ', array_column(DB::getQueryLog(), 'query')));
        DB::disableQueryLog();

        $this->assertStringNotContainsString('from `instructor_earnings`', $sql);
    }

    // ── System health is super-admin territory ───────────────────────

    public function test_manager_gets_no_system_health_section(): void
    {
        // The seeded manager role holds every reporting permission but
        // none of queue/scheduler/cache.
        $this->assertNull($this->compose($this->manager())->systemHealth);
    }

    public function test_super_admin_gets_the_system_health_section(): void
    {
        $health = $this->compose($this->superAdmin())->systemHealth;

        $this->assertNotNull($health);
        $this->assertNotSame([], $health->links);
    }

    public function test_manager_page_does_not_render_system_tool_links(): void
    {
        $response = $this->actingAs($this->manager())->get(Dashboard::getUrl())->assertOk();

        $response->assertDontSee('Queue Monitor');
        $response->assertDontSee('Cache Manager');
        $response->assertDontSee('Scheduler Monitor');
    }

    public function test_manager_dashboard_does_not_query_failed_jobs(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->compose($this->manager());

        $sql = strtolower(implode(' | ', array_column(DB::getQueryLog(), 'query')));
        DB::disableQueryLog();

        $this->assertStringNotContainsString('from `failed_jobs`', $sql);
    }

    // ── Compliance is restricted more tightly than the resource ──────

    public function test_suspicious_activity_is_not_offered_to_a_manager_on_the_dashboard(): void
    {
        $manager = $this->manager();
        Permission::firstOrCreate(['name' => 'ViewAny:SuspiciousActivityFlag', 'guard_name' => 'web']);
        $manager->givePermissionTo('ViewAny:SuspiciousActivityFlag');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $keys = $this->attentionKeys($manager);

        // Deliberately conservative: the sidebar resource stays reachable,
        // but fraud signals are not surfaced on the dashboard for managers.
        $this->assertNotContains('suspicious_activity', $keys);
    }

    // ── A failing section is contained, not fatal ────────────────────

    public function test_one_failing_report_service_does_not_break_the_dashboard(): void
    {
        $manager = $this->manager();

        // Simulate a report service failing at runtime. The dashboard an
        // operator opens during an incident must still render — the
        // affected section simply drops out.
        $this->app->bind(
            MarketplaceExecutiveReportServiceInterface::class,
            fn () => new ExplodingMarketplaceReportService,
        );

        $data = $this->compose($manager);

        // Marketplace figures are gone...
        $this->assertNull(
            collect($data->summaries)->firstWhere('key', 'marketplace'),
            'The failing section must be omitted.',
        );
        $this->assertNotContains(
            'active_instructors',
            array_map(fn ($kpi): string => $kpi->key, $data->kpis),
        );

        // ...but everything else still composed.
        $this->assertContains(
            'bookings_scheduled',
            array_map(fn ($kpi): string => $kpi->key, $data->kpis),
        );
    }

    public function test_the_page_still_returns_200_when_a_section_fails(): void
    {
        $this->app->bind(
            MarketplaceExecutiveReportServiceInterface::class,
            fn () => new ExplodingMarketplaceReportService,
        );

        $this->actingAs($this->manager())
            ->get(Dashboard::getUrl())
            ->assertOk();
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function compose(User $user): DashboardData
    {
        $this->actingAs($user);

        return app(DashboardCompositionService::class)->compose($user, $this->context());
    }

    /** @return list<string> */
    private function attentionKeys(User $user): array
    {
        $this->actingAs($user);

        return array_map(
            fn ($item): string => $item->key,
            app(AttentionFeedService::class)->build($user)->items,
        );
    }

    private function summary(DashboardData $data, string $key): ?DomainSummary
    {
        foreach ($data->summaries as $summary) {
            if ($summary->key === $key) {
                return $summary;
            }
        }

        return null;
    }

    private function context(): DashboardContext
    {
        return new DashboardContext(
            period: ReportingPeriod::forPreset(ReportingPeriodPreset::Last30Days, ReportingTimezoneResolver::resolve()),
            countryId: null,
        );
    }

    private function manager(): User
    {
        $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        $user->assignRole('manager');

        return $user;
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $user->assignRole('super_admin');

        return $user;
    }

    /**
     * An admin-portal user with no reporting permissions at all —
     * `manager` role for portal access, then every reporting permission
     * revoked so individual grants can be isolated.
     */
    private function adminWithoutReportingPermissions(): User
    {
        $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        $role = Role::findByName('manager', 'web');
        $user->assignRole($role);

        // Detach the role's bundled permissions from this user's effective
        // set by using a fresh role with no permissions instead.
        $user->removeRole($role);
        $bare = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $user->assignRole($bare);
        $bare->syncPermissions([]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }
}

/**
 * A marketplace report service that always fails, used to prove the
 * composition layer contains a section failure instead of propagating
 * it into a 500.
 */
final class ExplodingMarketplaceReportService implements MarketplaceExecutiveReportServiceInterface
{
    public function marketplaceSupply(User $user, ReportingPeriod $period, ReportFilters $filters): MarketplaceSupplyData
    {
        throw new \RuntimeException('Simulated marketplace reporting failure.');
    }

    public function marketplaceDemand(User $user, ReportingPeriod $period, ReportFilters $filters): MarketplaceDemandData
    {
        throw new \RuntimeException('Simulated marketplace reporting failure.');
    }

    public function marketplaceComparison(User $user, ReportingPeriod $period, ReportFilters $filters): MarketplaceComparisonData
    {
        throw new \RuntimeException('Simulated marketplace reporting failure.');
    }

    public function executiveOverview(User $user, ReportingPeriod $period, ReportFilters $filters): ExecutiveKpiOverviewData
    {
        throw new \RuntimeException('Simulated marketplace reporting failure.');
    }

    public function canViewMarketplace(User $user): bool
    {
        return true;
    }

    public function canViewExecutive(User $user): bool
    {
        return true;
    }

    public function freshness(ReportingPeriod $period): OperationsReportFreshnessData
    {
        throw new \RuntimeException('Simulated marketplace reporting failure.');
    }
}
