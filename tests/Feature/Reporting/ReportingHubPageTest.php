<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Filament\Pages\ReportingHub;
use App\Filament\Pages\ReviewsQualityDashboard;
use App\Models\User;
use App\Reporting\Enums\ReportCategory;
use Database\Seeders\ReportingPermissionSeeder;
use Database\Seeders\ReviewPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Phase 18B §18 — the permission-controlled reporting landing page. */
class ReportingHubPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReportingPermissionSeeder::class);
        $this->seed(ReviewPermissionSeeder::class);
    }

    // ── Full HTTP access (panel-level gate + page-level gate) ────────────

    public function test_authorized_administrator_can_open_the_page(): void
    {
        $manager = $this->manager(); // manager role bundles every reporting permission — see ReportingPermissionSeeder

        $this->actingAs($manager)
            ->get(ReportingHub::getUrl())
            ->assertOk()
            ->assertSee('Bookings & Lessons');
    }

    public function test_unauthorized_administrator_is_denied(): void
    {
        // Not a manager/super_admin at all — denied before this page's own
        // canAccess() even matters (App\Models\User::canAccessPanel()).
        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($user)
            ->get(ReportingHub::getUrl())
            ->assertForbidden();

        $this->assertFalse(ReportingHub::canAccess());
    }

    public function test_direct_route_access_is_denied_without_authentication(): void
    {
        $this->get(ReportingHub::getUrl())->assertRedirect();
    }

    public function test_planned_reports_render_with_no_link_and_no_fake_metric(): void
    {
        $superAdmin = $this->superAdmin();

        $response = $this->actingAs($superAdmin)->get(ReportingHub::getUrl())->assertOk();

        $response->assertSee('Executive Summary');
        $response->assertSee('Planned');
        // No fabricated numeric KPI values are ever rendered on this page.
        $response->assertDontSee('₹');
    }

    public function test_reviews_quality_dashboard_page_remains_separate_and_unaffected(): void
    {
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin)
            ->get(ReviewsQualityDashboard::getUrl())
            ->assertOk();
    }

    public function test_page_rendering_performs_no_report_domain_query(): void
    {
        $superAdmin = $this->superAdmin();

        // Warm caches/permissions first so only the page's own render is measured.
        $this->actingAs($superAdmin)->get(ReportingHub::getUrl());

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->actingAs($superAdmin)->get(ReportingHub::getUrl())->assertOk();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        foreach ($queries as $query) {
            $sql = strtolower($query['query']);
            $this->assertStringNotContainsString('from `bookings`', $sql);
            $this->assertStringNotContainsString('from `lesson_reviews`', $sql);
            $this->assertStringNotContainsString('from `wallet_ledger_entries`', $sql);
        }
    }

    // ── Category filtering (page-logic level — isolates one permission
    //    at a time, independent of the panel-level manager-role gate) ────

    public function test_only_authorized_categories_appear(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo('ViewBookingLessonReports'); // not ViewFinanceReports
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $keys = array_map(fn (array $row) => $row['category']->value, $this->categoriesFor($user));

        $this->assertContains(ReportCategory::BookingsLessons->value, $keys);
        $this->assertNotContains(ReportCategory::Finance->value, $keys);
    }

    public function test_finance_category_is_hidden_without_finance_permission(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo('ViewOperationalReports');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $keys = array_map(fn (array $row) => $row['category']->value, $this->categoriesFor($user));

        $this->assertNotContains(ReportCategory::Finance->value, $keys);
    }

    /** @return list<array{category: ReportCategory, available: Collection, planned: Collection}> */
    private function categoriesFor(User $user): array
    {
        $this->actingAs($user);

        return app(ReportingHub::class)->categories();
    }

    private function manager(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('manager');

        return $user;
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $user->assignRole('super_admin');

        return $user;
    }
}
