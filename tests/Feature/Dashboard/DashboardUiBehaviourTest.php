<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Alerts\DTOs\OperationalAlertSignal;
use App\Alerts\Enums\OperationalAlertCategory;
use App\Alerts\Enums\OperationalAlertSeverity;
use App\Alerts\Enums\OperationalAlertType;
use App\Alerts\Services\OperationalAlertService;
use App\Dashboard\DTOs\AttentionFeed;
use App\Dashboard\DTOs\DashboardContext;
use App\Dashboard\DTOs\DashboardData;
use App\Dashboard\Enums\AttentionSeverity;
use App\Dashboard\Services\AttentionFeedService;
use App\Dashboard\Services\DashboardCompositionService;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\RecentAuditTrailWidget;
use App\Filament\Widgets\RecentLoginsWidget;
use App\Filament\Widgets\RecentUsersWidget;
use App\Models\User;
use App\Reporting\Enums\ReportingPeriodPreset;
use App\Reporting\Support\ReportingTimezoneResolver;
use App\Reporting\ValueObjects\ReportingPeriod;
use App\Settings\PaymentGatewaySettings;
use Database\Seeders\OperationalAlertPermissionSeeder;
use Database\Seeders\ReportingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Presentation rules the redesign is defined by. */
class DashboardUiBehaviourTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReportingPermissionSeeder::class);
        $this->seed(OperationalAlertPermissionSeeder::class);
    }

    // ── No data tables ───────────────────────────────────────────────

    public function test_dashboard_composes_no_widgets(): void
    {
        $this->actingAs($this->manager());

        // The layout is explicit Blade; the inherited widget grid is
        // deliberately empty, so no table widget can slip back in.
        $this->assertSame([], (new Dashboard)->getWidgets());
    }

    public function test_dashboard_does_not_render_the_removed_activity_tables(): void
    {
        $response = $this->actingAs($this->superAdmin())->get(Dashboard::getUrl())->assertOk();

        $response->assertDontSee('Recent Registrations');
        $response->assertDontSee('Recent Login Activity');
        $response->assertDontSee('Recent Audit Trail');
    }

    public function test_table_widget_classes_survive_for_reuse_elsewhere(): void
    {
        // Removed from the dashboard, not deleted — each remains a real,
        // permission-gated widget other surfaces may still register.
        foreach ([RecentUsersWidget::class, RecentLoginsWidget::class, RecentAuditTrailWidget::class] as $widget) {
            $this->assertTrue(class_exists($widget));
            $this->assertTrue(method_exists($widget, 'canView'));
        }
    }

    public function test_dashboard_does_not_render_generic_user_administration_statistics(): void
    {
        $response = $this->actingAs($this->superAdmin())->get(Dashboard::getUrl())->assertOk();

        $response->assertDontSee('Total Users');
        $response->assertDontSee('Active Users');
        $response->assertDontSee("Today's Logins");
    }

    public function test_content_authoring_actions_are_gone_from_the_dashboard(): void
    {
        $response = $this->actingAs($this->superAdmin())->get(Dashboard::getUrl())->assertOk();

        $response->assertDontSee('New Page');
        $response->assertDontSee('New Post');
        $response->assertDontSee('Create Role');
    }

    // ── Attention card limits ────────────────────────────────────────

    public function test_at_most_six_attention_cards_are_visible(): void
    {
        $manager = $this->managerWithAlertAccess();

        // Ten distinct open alerts across both partitions plus the
        // integrity cards comfortably exceeds the cap.
        $this->seedAlerts(10);

        $this->actingAs($manager);
        $feed = app(AttentionFeedService::class)->build($manager);

        $this->assertLessThanOrEqual(AttentionFeed::MAX_VISIBLE, count($feed->visible()));
        $this->assertSame(6, AttentionFeed::MAX_VISIBLE);
    }

    public function test_overflow_items_are_reachable_rather_than_dropped(): void
    {
        $manager = $this->managerWithAlertAccess();
        $this->seedAlerts(4);

        $this->actingAs($manager);
        $feed = app(AttentionFeedService::class)->build($manager);

        $this->assertSame(
            count($feed->items),
            count($feed->visible()) + count($feed->overflow()),
            'Every item must be either visible or in the overflow — none may vanish.',
        );
    }

    public function test_attention_items_are_sorted_by_severity(): void
    {
        $manager = $this->managerWithAlertAccess();
        $this->seedAlerts(3);

        $this->actingAs($manager);
        $items = app(AttentionFeedService::class)->build($manager)->items;

        $ranks = array_map(
            static fn ($item): int => $item->effectiveSeverity()->rank(),
            $items,
        );

        $sorted = $ranks;
        sort($sorted);

        $this->assertSame($sorted, $ranks, 'Critical must precede High, then Warning, then Info.');
    }

    public function test_healthy_integrity_cards_sort_last(): void
    {
        $manager = $this->managerWithAlertAccess();
        $this->seedAlerts(2);

        $this->actingAs($manager);
        $items = app(AttentionFeedService::class)->build($manager)->items;

        $lastRank = end($items)->effectiveSeverity();

        // With no real problems beyond the seeded alerts, the zero
        // integrity confirmations must not crowd out actual work.
        $this->assertSame(AttentionSeverity::Success, $lastRank);
    }

    // ── Provider activation is distinct from zero activity ───────────

    public function test_money_summary_states_that_no_collection_provider_is_activated(): void
    {
        $manager = $this->manager();

        $money = collect($this->compose($manager)->summaries)->firstWhere('key', 'money');

        $this->assertNotNull($money);
        $this->assertNotNull($money->notice);
        $this->assertStringContainsString('No collection provider is activated', $money->notice);
    }

    public function test_the_notice_disappears_once_a_provider_is_genuinely_live(): void
    {
        $settings = app(PaymentGatewaySettings::class);
        $settings->razorpay_enabled = true;
        $settings->razorpay_config_status = 'ready';
        $settings->save();

        $money = collect($this->compose($this->manager())->summaries)->firstWhere('key', 'money');

        $this->assertNull($money->notice);
    }

    public function test_enabled_but_unverified_credentials_do_not_count_as_activated(): void
    {
        $settings = app(PaymentGatewaySettings::class);
        $settings->razorpay_enabled = true;
        // Switched on, but the configuration service has not validated it.
        $settings->razorpay_config_status = 'incomplete';
        $settings->save();

        $money = collect($this->compose($this->manager())->summaries)->firstWhere('key', 'money');

        $this->assertNotNull($money->notice);
    }

    public function test_provider_state_never_exposes_a_credential(): void
    {
        $settings = app(PaymentGatewaySettings::class);
        $settings->razorpay_enabled = true;
        $settings->razorpay_key_id = 'rzp_test_SECRETVALUE';
        $settings->razorpay_key_secret = 'super-secret-value';
        $settings->save();

        $health = $this->compose($this->superAdmin())->systemHealth;

        $serialized = json_encode(array_map(
            static fn ($provider): array => [
                $provider->label, $provider->statusLabel, $provider->detail,
            ],
            $health->providers,
        ));

        $this->assertStringNotContainsString('rzp_test_SECRETVALUE', (string) $serialized);
        $this->assertStringNotContainsString('super-secret-value', (string) $serialized);
    }

    // ── Degraded permissions stay coherent ───────────────────────────

    public function test_page_renders_for_an_admin_with_no_reporting_permissions(): void
    {
        $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        $role = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $role->syncPermissions([]);
        $user->assignRole($role);

        $this->actingAs($user)
            ->get(Dashboard::getUrl())
            ->assertOk()
            ->assertSee('No reporting sections are available to you');
    }

    public function test_page_renders_with_only_a_partial_permission_set(): void
    {
        $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        $role = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $role->syncPermissions([]);
        $user->assignRole($role);
        $user->givePermissionTo('ViewStudentReports');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user)
            ->get(Dashboard::getUrl())
            ->assertOk()
            ->assertSee('Engaged students');
    }

    public function test_dashboard_shows_the_reporting_timezone_and_period(): void
    {
        $response = $this->actingAs($this->manager())->get(Dashboard::getUrl())->assertOk();

        $response->assertSee(ReportingTimezoneResolver::resolve());
        $response->assertSee('Attention counts: as of');
        $response->assertSee('Period figures:');
    }

    // ── Section caps ─────────────────────────────────────────────────

    public function test_at_most_six_primary_kpis_and_reports(): void
    {
        $data = $this->compose($this->superAdmin());

        $this->assertLessThanOrEqual(DashboardCompositionService::MAX_PRIMARY_KPIS, count($data->kpis));
        $this->assertLessThanOrEqual(DashboardCompositionService::MAX_PRIMARY_REPORTS, count($data->primaryReports));
    }

    public function test_at_most_five_charts_are_composed(): void
    {
        $this->assertLessThanOrEqual(5, count($this->compose($this->superAdmin())->charts));
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * Uses the domain's own authoritative writer rather than inserting
     * rows by hand, so the test exercises the same dedup/fingerprint
     * path production does and cannot drift from the schema.
     */
    private function seedAlerts(int $count): void
    {
        $service = app(OperationalAlertService::class);
        $types = OperationalAlertType::cases();

        for ($i = 0; $i < $count; $i++) {
            $service->createOrMerge(new OperationalAlertSignal(
                type: $types[$i % count($types)],
                category: OperationalAlertCategory::CrossDomainCritical,
                severity: OperationalAlertSeverity::High,
                title: 'Seeded alert '.$i,
                summary: 'Seeded for dashboard ordering tests.',
                // A distinct subject per alert keeps each fingerprint
                // unique, so they stay separate rows instead of merging.
                subjectType: 'test-subject',
                subjectId: 'subject-'.$i,
            ));
        }
    }

    private function compose(User $user): DashboardData
    {
        $this->actingAs($user);

        return app(DashboardCompositionService::class)->compose($user, new DashboardContext(
            period: ReportingPeriod::forPreset(ReportingPeriodPreset::Last30Days, ReportingTimezoneResolver::resolve()),
            countryId: null,
        ));
    }

    private function manager(): User
    {
        $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        $user->assignRole(Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']));

        return $user;
    }

    private function managerWithAlertAccess(): User
    {
        return $this->manager();
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $user->assignRole('super_admin');

        return $user;
    }
}
