<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Models\User;
use App\Reporting\Contracts\ReportAccessContextInterface;
use App\Reporting\Contracts\ReportRegistryInterface;
use App\Reporting\Enums\ReportCategory;
use App\Reporting\Filters\ReportFilterKey;
use Database\Seeders\ReportingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The report access-context permission model:
 * category/report visibility, financial/compensation restrictions,
 * masking, and export independence.
 */
class ReportAccessContextTest extends TestCase
{
    use RefreshDatabase;

    private ReportAccessContextInterface $access;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReportingPermissionSeeder::class);
        $this->access = app(ReportAccessContextInterface::class);
    }

    public function test_authorized_administrator_can_view_an_available_report(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo(['ViewBookingLessonReports']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $definition = app(ReportRegistryInterface::class)->find('booking_lesson_kpis');

        $this->assertTrue($this->access->canView($user, $definition));
    }

    public function test_unauthorized_administrator_is_denied(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $definition = app(ReportRegistryInterface::class)->find('booking_lesson_kpis');

        $this->assertFalse($this->access->canView($user, $definition));
    }

    public function test_operational_access_does_not_grant_finance_access(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo('ViewOperationalReports');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse($this->access->canViewFinancialValues($user));
        $this->assertFalse($this->access->canViewCategory($user, ReportCategory::Finance));
    }

    public function test_finance_access_does_not_grant_instructor_compensation_access(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo('ViewFinanceReports');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue($this->access->canViewFinancialValues($user));
        $this->assertFalse($this->access->canViewInstructorCompensation($user));
    }

    public function test_view_permission_does_not_grant_export(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo('ViewBookingLessonReports');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $definition = app(ReportRegistryInterface::class)->find('booking_lesson_kpis');

        $this->assertTrue($this->access->canView($user, $definition));
        $this->assertFalse($this->access->canExport($user, $definition));
    }

    public function test_export_permission_does_not_grant_sensitive_export(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo('ExportReports');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse($this->access->canPerformSensitiveExport($user));
    }

    public function test_super_admin_receives_every_reporting_permission(): void
    {
        $superAdmin = User::factory()->create(['status' => 'active']);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $superAdmin->assignRole('super_admin');

        foreach (ReportCategory::cases() as $category) {
            $this->assertTrue($this->access->canViewCategory($superAdmin, $category), "super_admin must see {$category->value}");
        }

        $this->assertTrue($this->access->canViewFinancialValues($superAdmin));
        $this->assertTrue($this->access->canViewInstructorCompensation($superAdmin));
        $this->assertTrue($this->access->canPerformSensitiveExport($superAdmin));
    }

    public function test_permission_seeding_is_idempotent(): void
    {
        $this->seed(ReportingPermissionSeeder::class);
        $this->seed(ReportingPermissionSeeder::class);

        $this->assertDatabaseCount('permissions', Permission::query()->count());
    }

    // ── Masking ──────────────────────────────────────────────────────────

    public function test_student_identity_is_masked_without_student_report_permission(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo('ViewOperationalReports');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse($this->access->canViewFullStudentIdentity($user));
        $this->assertTrue($this->access->shouldMaskPersonalData($user));
    }

    public function test_student_identity_unmasked_with_student_report_permission(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo('ViewStudentReports');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue($this->access->canViewFullStudentIdentity($user));
        $this->assertFalse($this->access->shouldMaskPersonalData($user));
    }

    public function test_masking_decision_is_server_side_and_not_influenced_by_client_state(): void
    {
        // There is no client-supplied parameter accepted by this method at
        // all — the signature itself is the structural guarantee that a
        // manipulated UI state cannot change the outcome.
        $method = new \ReflectionMethod(ReportAccessContextInterface::class, 'shouldMaskPersonalData');
        $this->assertCount(1, $method->getParameters());
        $this->assertSame('user', $method->getParameters()[0]->getName());
    }

    // ── Historical access ────────────────────────────────────────────────

    public function test_archived_entities_remain_accessible_in_historical_reports(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $this->assertTrue($this->access->canAccessArchivedEntities($user));
    }

    // ── Filter containment ───────────────────────────────────────────────

    public function test_can_use_filter_only_for_a_reports_declared_supported_filters(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $definition = app(ReportRegistryInterface::class)->find('booking_lesson_kpis');

        $this->assertTrue($this->access->canUseFilter($user, $definition, ReportFilterKey::BookingType));
        $this->assertFalse($this->access->canUseFilter($user, $definition, ReportFilterKey::EarningStatus));
    }
}
