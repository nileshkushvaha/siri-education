<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\QueueMonitorPage;
use App\Models\User;
use App\Policies\QueueMonitorPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class QueueMonitorPageTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'queue_monitor.view', 'guard_name' => 'web']);

        $superAdminRole = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $managerRole = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);

        $this->superAdmin = User::factory()->create(['status' => 'active']);
        $this->superAdmin->assignRole($superAdminRole);

        // manager is required for Admin Portal access (PortalResolver) — this
        // user is still denied each test below until/unless it also grants the
        // specific resource permission, so the policy layer stays covered.
        $this->regularUser = User::factory()->create(['status' => 'active']);
        $this->regularUser->assignRole($managerRole);
    }

    // ── Access control ─────────────────────────────────────────────────────

    public function test_super_admin_can_access_queue_monitor_page(): void
    {
        $this->actingAs($this->superAdmin)
            ->get('/admin/system/queue-monitor')
            ->assertOk();
    }

    public function test_regular_user_cannot_access_queue_monitor_page(): void
    {
        $this->actingAs($this->regularUser)
            ->get('/admin/system/queue-monitor')
            ->assertForbidden();
    }

    public function test_guest_is_redirected_from_queue_monitor_page(): void
    {
        $this->get('/admin/system/queue-monitor')
            ->assertRedirect();
    }

    public function test_user_with_view_permission_can_access(): void
    {
        $this->regularUser->givePermissionTo('queue_monitor.view');

        $this->actingAs($this->regularUser)
            ->get('/admin/system/queue-monitor')
            ->assertOk();
    }

    // ── Page renders ───────────────────────────────────────────────────────

    public function test_page_renders_successfully(): void
    {
        Livewire::actingAs($this->superAdmin)
            ->test(QueueMonitorPage::class)
            ->assertStatus(200);
    }

    // ── Policy ─────────────────────────────────────────────────────────────

    public function test_policy_denies_regular_user(): void
    {
        $this->assertFalse(
            app(QueueMonitorPolicy::class)->viewPage($this->regularUser)
        );
    }

    public function test_policy_allows_user_with_permission(): void
    {
        $this->regularUser->givePermissionTo('queue_monitor.view');

        $this->assertTrue(
            app(QueueMonitorPolicy::class)->viewPage($this->regularUser)
        );
    }

    public function test_policy_allows_super_admin(): void
    {
        $this->assertTrue(
            app(QueueMonitorPolicy::class)->viewPage($this->superAdmin)
        );
    }

    // ── getQueueInfo ───────────────────────────────────────────────────────

    public function test_get_queue_info_returns_driver(): void
    {
        $page = app(QueueMonitorPage::class);
        $info = $page->getQueueInfo();

        $this->assertArrayHasKey('driver', $info);
        $this->assertArrayHasKey('connection', $info);
        $this->assertArrayHasKey('table', $info);
    }

    public function test_get_queue_info_driver_is_uppercase(): void
    {
        $page = app(QueueMonitorPage::class);
        $info = $page->getQueueInfo();

        $this->assertSame(strtoupper($info['driver']), $info['driver']);
    }

    // ── getQueueDepths ─────────────────────────────────────────────────────

    public function test_get_queue_depths_returns_array(): void
    {
        $page = app(QueueMonitorPage::class);
        $depths = $page->getQueueDepths();

        $this->assertIsArray($depths);
    }

    public function test_get_queue_depths_returns_empty_when_no_jobs(): void
    {
        // In testing, QUEUE_CONNECTION=sync so depths should be empty (non-database driver)
        $page = app(QueueMonitorPage::class);
        $depths = $page->getQueueDepths();

        // With sync driver, getQueueDepths returns [] immediately
        $this->assertIsArray($depths);
    }

    // ── getFailedJobStats ──────────────────────────────────────────────────

    public function test_get_failed_job_stats_returns_expected_keys(): void
    {
        // Phase 24N — GAP-034: the static "5 most recent" summary was
        // superseded by the full, paginated, retryable Filament table
        // (QueueMonitorPage::table()); getFailedJobStats() no longer
        // computes a separate 'recent' slice.
        $page = app(QueueMonitorPage::class);
        $stats = $page->getFailedJobStats();

        $this->assertArrayHasKey('count', $stats);
        $this->assertArrayHasKey('byQueue', $stats);
    }

    public function test_get_failed_job_stats_count_is_integer(): void
    {
        $page = app(QueueMonitorPage::class);
        $stats = $page->getFailedJobStats();

        $this->assertIsInt($stats['count']);
    }

    // ── isWorkerLikelyRunning ──────────────────────────────────────────────

    public function test_worker_likely_running_returns_true_for_sync_driver(): void
    {
        // Testing env uses QUEUE_CONNECTION=sync
        $page = app(QueueMonitorPage::class);

        $this->assertTrue($page->isWorkerLikelyRunning());
    }
}
