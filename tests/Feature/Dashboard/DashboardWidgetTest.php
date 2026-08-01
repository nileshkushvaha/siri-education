<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\QuickActionsWidget;
use App\Filament\Widgets\RecentAuditTrailWidget;
use App\Filament\Widgets\RecentLoginsWidget;
use App\Filament\Widgets\RecentUsersWidget;
use App\Filament\Widgets\StatsOverviewWidget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The legacy dashboard widgets after the marketplace redesign.
 *
 * These five widgets used to BE the dashboard. They are no longer part
 * of its composition — the page now renders marketplace figures through
 * an explicit Blade layout, and `Dashboard::getWidgets()` returns
 * nothing — but the classes themselves are intact, still permission-
 * gated, and still registered as Livewire components, so any surface
 * that wants them can render one.
 *
 * This file therefore covers two things: that each widget's own
 * `canView()` still behaves, and that none of them can reach the
 * dashboard again by accident.
 */
class DashboardWidgetTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $manager;

    private const WIDGET_PERMISSIONS = [
        StatsOverviewWidget::class => 'View:StatsOverviewWidget',
        RecentUsersWidget::class => 'View:RecentUsersWidget',
        RecentLoginsWidget::class => 'View:RecentLoginsWidget',
        RecentAuditTrailWidget::class => 'View:RecentAuditTrailWidget',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $superAdminRole = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $managerRole = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);

        foreach (self::WIDGET_PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        foreach ([
            'ViewAny:User', 'ViewAny:Page', 'ViewAny:Post', 'ViewAny:Role',
            'ViewAny:Activity', 'ViewAny:LoginHistory',
            'Create:User', 'Create:Page', 'Create:Post', 'Create:Role',
            'View:GeneralSettingsPage',
        ] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }

        $this->superAdmin = User::factory()->create([
            'status' => 'active',
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);
        $this->superAdmin->assignRole($superAdminRole);

        $this->manager = User::factory()->create([
            'status' => 'active',
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
        ]);
        $this->manager->assignRole($managerRole);
    }

    // ── canView() via Gate::before() — super_admin sees everything ────────────

    public function test_super_admin_can_view_stats_widget(): void
    {
        $this->actingAs($this->superAdmin);
        $this->assertTrue(StatsOverviewWidget::canView());
    }

    public function test_super_admin_can_view_recent_users_widget(): void
    {
        $this->actingAs($this->superAdmin);
        $this->assertTrue(RecentUsersWidget::canView());
    }

    public function test_super_admin_can_view_recent_logins_widget(): void
    {
        $this->actingAs($this->superAdmin);
        $this->assertTrue(RecentLoginsWidget::canView());
    }

    public function test_super_admin_can_view_audit_trail_widget(): void
    {
        $this->actingAs($this->superAdmin);
        $this->assertTrue(RecentAuditTrailWidget::canView());
    }

    public function test_super_admin_can_view_quick_actions_widget(): void
    {
        $this->actingAs($this->superAdmin);
        $this->assertTrue(QuickActionsWidget::canView());
    }

    // ── Manager sees only explicitly assigned widgets ─────────────────────────

    public function test_manager_without_permissions_cannot_view_stats_widget(): void
    {
        $this->actingAs($this->manager);
        $this->assertFalse(StatsOverviewWidget::canView());
    }

    public function test_manager_with_permission_can_view_stats_widget(): void
    {
        $this->manager->givePermissionTo('View:StatsOverviewWidget');
        $this->actingAs($this->manager);

        $this->assertTrue(StatsOverviewWidget::canView());
    }

    public function test_manager_with_permission_can_view_recent_users_widget(): void
    {
        $this->manager->givePermissionTo('View:RecentUsersWidget');
        $this->actingAs($this->manager);

        $this->assertTrue(RecentUsersWidget::canView());
    }

    public function test_manager_without_permission_cannot_view_recent_users_widget(): void
    {
        $this->actingAs($this->manager);
        $this->assertFalse(RecentUsersWidget::canView());
    }

    // ── None of them is part of the dashboard any more ───────────────────────

    public function test_dashboard_composes_no_widgets_for_super_admin(): void
    {
        $this->actingAs($this->superAdmin);

        // Even with every permission, the dashboard's widget grid is empty:
        // its content comes from the composition layer and its own Blade
        // layout, not from panel-registered widgets.
        $this->assertSame([], (new Dashboard)->getWidgets());
    }

    public function test_dashboard_composes_no_widgets_for_manager(): void
    {
        $this->actingAs($this->manager);

        $this->assertSame([], (new Dashboard)->getWidgets());
    }

    public function test_legacy_dashboard_widgets_are_not_reachable_through_the_dashboard(): void
    {
        $this->actingAs($this->superAdmin);

        $widgets = (new Dashboard)->getWidgets();

        foreach ([
            StatsOverviewWidget::class,
            RecentUsersWidget::class,
            RecentLoginsWidget::class,
            RecentAuditTrailWidget::class,
            QuickActionsWidget::class,
        ] as $widget) {
            $this->assertNotContains($widget, $widgets);
        }
    }

    public function test_the_hardcoded_widget_constant_is_gone(): void
    {
        // The old page carried a WIDGETS constant listing its grid. The
        // redesign replaced it with a composition service, so a stale
        // constant must not linger and mislead.
        $this->assertFalse((new \ReflectionClass(Dashboard::class))->hasConstant('WIDGETS'));
    }

    // ── Quick Actions — retained class, still permission-aware ───────────────

    public function test_super_admin_quick_actions_shows_create_labels(): void
    {
        $this->actingAs($this->superAdmin);

        $actions = (new QuickActionsWidget)->getActions();
        $labels = array_column($actions, 'label');

        $this->assertContains('Create User', $labels);
        $this->assertContains('New Page', $labels);
        $this->assertContains('New Post', $labels);
        $this->assertContains('Create Role', $labels);
    }

    public function test_manager_with_view_any_user_sees_users_index_card(): void
    {
        $this->manager->givePermissionTo('ViewAny:User');
        $this->actingAs($this->manager);

        $actions = (new QuickActionsWidget)->getActions();
        $labels = array_column($actions, 'label');
        $urls = array_column($actions, 'url');

        $this->assertContains('Users', $labels);
        $this->assertContains(route('filament.admin.resources.users.index'), $urls);
        $this->assertNotContains('Create User', $labels);
    }

    public function test_manager_with_create_user_sees_create_user_card(): void
    {
        $this->manager->givePermissionTo(['ViewAny:User', 'Create:User']);
        $this->actingAs($this->manager);

        $actions = (new QuickActionsWidget)->getActions();
        $labels = array_column($actions, 'label');
        $urls = array_column($actions, 'url');

        $this->assertContains('Create User', $labels);
        $this->assertContains(route('filament.admin.resources.users.create'), $urls);
    }

    public function test_manager_with_no_permissions_sees_no_quick_action_cards(): void
    {
        $this->actingAs($this->manager);
        $this->assertEmpty((new QuickActionsWidget)->getActions());
    }

    // ── Widgets still render on their own ────────────────────────────────────

    public function test_stats_widget_still_renders_when_mounted_directly(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(StatsOverviewWidget::class)->assertSuccessful();
    }
}
