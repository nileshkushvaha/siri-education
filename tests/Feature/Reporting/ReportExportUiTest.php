<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Filament\Pages\BookingReports;
use App\Filament\Pages\StudentEngagement;
use App\Models\User;
use Database\Seeders\ReportingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Phase 18I — export UI: action visibility follows export
 * authorization, and the hardened legacy Booking Reports CSV keeps its
 * columns/filename while gaining the shared permission gate and audit
 * lifecycle.
 */
class ReportExportUiTest extends TestCase
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

    public function test_export_button_renders_only_with_export_permission(): void
    {
        $admin = $this->manager();

        $this->actingAs($admin)->get(StudentEngagement::getUrl())
            ->assertOk()
            ->assertSee('Export CSV');

        Role::findByName('manager', 'web')->revokePermissionTo('ExportReports');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($admin)->get(StudentEngagement::getUrl())
            ->assertOk()
            ->assertDontSee('Export CSV');
    }

    public function test_direct_livewire_export_call_is_denied_without_permission(): void
    {
        $admin = $this->manager();
        Role::findByName('manager', 'web')->revokePermissionTo('ExportReports');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($admin);

        Livewire::test(StudentEngagement::class)
            ->call('exportCsv', 'student_engagement_rows')
            ->assertStatus(403); // server-side authorization rejects the direct call

        // No export audit event may exist for the denied call.
        $this->assertSame(0, DB::table('activity_log')->where('log_name', 'reporting')->where('event', 'like', 'report_export_%')->count());
    }

    public function test_legacy_booking_kpi_export_is_gated_and_audited_with_unchanged_format(): void
    {
        $admin = $this->manager();
        $this->actingAs($admin);

        $this->assertTrue(BookingReports::canExportKpis());

        // Invoke the export directly — the header action delegates here.
        $page = new BookingReports;
        $method = new \ReflectionMethod($page, 'exportKpis');
        $response = $method->invoke($page);

        ob_start();
        $response->sendContent();
        $body = (string) ob_get_clean();

        // Legacy columns unchanged (Phase 18I compatibility guarantee).
        $this->assertStringContainsString('Section,Metric,Value', $body);
        $this->assertStringContainsString('Total bookings', $body);

        $events = DB::table('activity_log')
            ->where('log_name', 'reporting')
            ->where('event', 'like', 'report_export_%')
            ->orderBy('id')
            ->get(['event', 'properties']);

        $this->assertCount(2, $events);
        $this->assertSame('report_export_requested', $events[0]->event);
        $this->assertSame('report_export_completed', $events[1]->event);
        $this->assertStringContainsString('booking_lesson_kpis', (string) $events[0]->properties);
    }

    public function test_legacy_export_hidden_without_export_permission(): void
    {
        $admin = $this->manager();
        Role::findByName('manager', 'web')->revokePermissionTo('ExportReports');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($admin);

        // The header action's visibility closure delegates to this gate, and
        // exportKpis() aborts 403 server-side on direct invocation.
        $this->assertFalse(BookingReports::canExportKpis());

        $page = new BookingReports;
        $method = new \ReflectionMethod($page, 'exportKpis');

        try {
            $method->invoke($page);
            $this->fail('Expected a 403 abort.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }
}
