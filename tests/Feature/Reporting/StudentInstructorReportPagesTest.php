<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Booking\Enums\BookingPaymentStatus;
use App\Enums\InstructorStatus;
use App\Filament\Pages\InstructorPerformance;
use App\Filament\Pages\ReportingHub;
use App\Filament\Pages\StudentEngagement;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\User;
use App\Reporting\Contracts\ReportRegistryInterface;
use Database\Seeders\ReportingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The two report pages: independent permission gates,
 * no financial rendering, registry integration, Livewire hydration
 * safety, and Reporting Hub listing.
 */
class StudentInstructorReportPagesTest extends TestCase
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

        $this->actingAs($admin)->get(StudentEngagement::getUrl())
            ->assertOk()
            ->assertSee('Reporting timezone')
            ->assertSee('Live query');

        $this->actingAs($admin)->get(InstructorPerformance::getUrl())
            ->assertOk()
            ->assertSee('Reporting timezone')
            ->assertSee('Live query');
    }

    public function test_non_admin_is_denied_on_both_pages(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($user)->get(StudentEngagement::getUrl())->assertForbidden();
        $this->actingAs($user)->get(InstructorPerformance::getUrl())->assertForbidden();
        $this->assertFalse(StudentEngagement::canAccess());
        $this->assertFalse(InstructorPerformance::canAccess());
    }

    public function test_guest_direct_route_access_is_denied(): void
    {
        $this->get(StudentEngagement::getUrl())->assertRedirect();
        $this->get(InstructorPerformance::getUrl())->assertRedirect();
    }

    public function test_student_report_permission_gates_only_the_student_page(): void
    {
        $admin = $this->manager();
        // Remove the instructor-report permission from the role — student page stays reachable, instructor page is not.
        Role::findByName('manager', 'web')->revokePermissionTo('ViewInstructorReports');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($admin)->get(StudentEngagement::getUrl())->assertOk();
        $this->actingAs($admin)->get(InstructorPerformance::getUrl())->assertForbidden();
    }

    public function test_instructor_report_permission_gates_only_the_instructor_page(): void
    {
        $admin = $this->manager();
        Role::findByName('manager', 'web')->revokePermissionTo('ViewStudentReports');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($admin)->get(InstructorPerformance::getUrl())->assertOk();
        $this->actingAs($admin)->get(StudentEngagement::getUrl())->assertForbidden();
    }

    public function test_instructor_page_hides_quality_section_without_quality_permission(): void
    {
        $admin = $this->manager();
        Role::findByName('manager', 'web')->revokePermissionTo('ViewReviewQualityReports');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($admin)->get(InstructorPerformance::getUrl())
            ->assertOk()
            ->assertSee('You do not have permission to view quality metrics.')
            ->assertDontSee('Platform average rating');
    }

    // ── No financial rendering ───────────────────────────────────────────

    public function test_pages_render_no_price_or_currency_values(): void
    {
        $admin = $this->manager();
        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->profile->update(['profile_visibility' => 'public', 'instructor_status' => InstructorStatus::Approved]);
        $instructor->assignRole('instructor');
        $student = User::factory()->create(['status' => 'active']);
        $student->assignRole('student');

        Booking::factory()->confirmed()->create([
            'booking_type_id' => BookingType::factory()->paid()->create(),
            'instructor_id' => $instructor->id,
            'student_id' => $student->id,
            'starts_at' => now()->subHours(3),
            'ends_at' => now()->subHours(2),
            'payment_status' => BookingPaymentStatus::Paid,
            'price' => '7654.32',
            'currency' => 'INR',
        ]);

        $this->actingAs($admin)->get(StudentEngagement::getUrl())
            ->assertOk()->assertDontSee('7654.32')->assertDontSee('₹');

        $this->actingAs($admin)->get(InstructorPerformance::getUrl())
            ->assertOk()->assertDontSee('7654.32')->assertDontSee('₹');
    }

    // ── Livewire hydration (18C regression class) ────────────────────────

    public function test_string_filter_values_hydrate_without_type_errors(): void
    {
        Livewire::actingAs($this->manager())
            ->test(StudentEngagement::class)
            ->set('countryId', '7')
            ->set('educationLevelId', (string) Str::uuid())
            ->set('studentStatus', 'active')
            ->set('studentStatus', '')
            ->assertOk();

        Livewire::actingAs($this->manager())
            ->test(InstructorPerformance::class)
            ->set('countryId', '7')
            ->set('subjectId', (string) Str::uuid())
            ->set('instructorId', '12')
            ->set('instructorStatus', 'approved')
            ->set('instructorStatus', '')
            ->assertOk();
    }

    // ── Registry integration ─────────────────────────────────────────────

    public function test_both_reports_registered_available_with_real_routes(): void
    {
        $registry = app(ReportRegistryInterface::class);

        $students = $registry->find('student_engagement');
        $this->assertNotNull($students);
        $this->assertTrue($students->available);
        $this->assertSame(StudentEngagement::class, $students->routeName);
        $this->assertSame('ViewStudentReports', $students->requiredViewPermission);

        $instructors = $registry->find('instructor_performance');
        $this->assertNotNull($instructors);
        $this->assertTrue($instructors->available);
        $this->assertSame(InstructorPerformance::class, $instructors->routeName);
        $this->assertSame('ViewInstructorReports', $instructors->requiredViewPermission);
    }

    public function test_reporting_hub_lists_both_reports_as_available(): void
    {
        $this->actingAs($this->manager())
            ->get(ReportingHub::getUrl())
            ->assertOk()
            ->assertSee('Student Engagement')
            ->assertSee('Instructor Performance');
    }
}
