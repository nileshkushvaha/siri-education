<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Booking\Enums\BookingPaymentStatus;
use App\Enums\InstructorStatus;
use App\Filament\Pages\BookingLessonMeetingOperations;
use App\Filament\Pages\BookingReports;
use App\Filament\Pages\ReportingHub;
use App\Models\AcademicCategory;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Subject;
use App\Models\User;
use App\Reporting\Contracts\ReportRegistryInterface;
use Database\Seeders\BookingPermissionSeeder;
use Database\Seeders\ReportingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 18C §8/§19 — the Booking, Lesson & Meeting Operations Filament
 * page: access control, permission-separated sections, timezone/
 * freshness display, and coexistence with the pages that predate it.
 */
class BookingLessonMeetingOperationsPageTest extends TestCase
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

    // ── Access control ────────────────────────────────────────────────────

    public function test_authorized_manager_can_open_the_page(): void
    {
        $this->actingAs($this->manager())
            ->get(BookingLessonMeetingOperations::getUrl())
            ->assertOk()
            ->assertSee('Booking, Lesson &amp; Meeting Operations', false)
            ->assertSee('Reporting timezone')
            ->assertSee('Live query');
    }

    public function test_non_admin_user_is_denied(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($user)
            ->get(BookingLessonMeetingOperations::getUrl())
            ->assertForbidden();

        $this->assertFalse(BookingLessonMeetingOperations::canAccess());
    }

    public function test_guest_direct_route_access_is_denied(): void
    {
        $this->get(BookingLessonMeetingOperations::getUrl())->assertRedirect();
    }

    public function test_manager_without_meeting_permission_sees_booking_but_not_meeting_section(): void
    {
        $admin = $this->manager();
        // The permission is held via the manager role, not directly —
        // revoking it from the role (safe under RefreshDatabase) is what
        // actually removes it.
        Role::findByName('manager', 'web')->revokePermissionTo('ViewMeetingReports');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($admin)
            ->get(BookingLessonMeetingOperations::getUrl())
            ->assertOk()
            ->assertSee('Total bookings')
            ->assertSee('You do not have permission to view meeting operations data.')
            ->assertDontSee('Meetings created');
    }

    public function test_manager_with_meeting_permission_sees_meeting_section(): void
    {
        $this->actingAs($this->manager())
            ->get(BookingLessonMeetingOperations::getUrl())
            ->assertOk()
            ->assertSee('Meetings created')
            ->assertSee('Meeting issues');
    }

    // ── Livewire hydration (regression: select values arrive as strings) ──

    public function test_string_select_values_hydrate_without_a_type_error(): void
    {
        // HTML selects/number inputs always submit strings — a typed ?int
        // property throws "Cannot assign string to property" on Livewire
        // hydration (reported live against the first build of this page).
        $subject = Subject::query()->create([
            'academic_category_id' => AcademicCategory::query()->firstOrCreate(['slug' => 'general'], ['name' => 'General'])->id,
            'name' => 'Maths',
            'slug' => 'maths',
            'status' => 'active',
        ]);

        Livewire::actingAs($this->manager())
            ->test(BookingLessonMeetingOperations::class)
            ->set('subjectId', (string) $subject->id)
            ->set('countryId', '7')
            ->set('instructorId', '12')
            ->set('bookingType', '')
            ->assertOk();
    }

    public function test_clearing_a_filter_back_to_empty_string_is_treated_as_null(): void
    {
        Livewire::actingAs($this->manager())
            ->test(BookingLessonMeetingOperations::class)
            ->set('subjectId', '3')
            ->set('subjectId', '')
            ->assertOk();
    }

    public function test_custom_preset_with_blank_dates_falls_back_safely(): void
    {
        Livewire::actingAs($this->manager())
            ->test(BookingLessonMeetingOperations::class)
            ->set('periodPreset', 'custom')
            ->set('customStart', '')
            ->set('customEnd', '')
            ->assertOk();
    }

    // ── No financial data ─────────────────────────────────────────────────

    public function test_page_renders_no_price_or_currency_values(): void
    {
        $admin = $this->manager();
        $type = BookingType::factory()->paid()->create(['key' => 'paid_one_to_one']);
        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->profile->update(['profile_visibility' => 'public', 'instructor_status' => InstructorStatus::Approved]);
        $instructor->assignRole('instructor');

        Booking::factory()->confirmed()->create([
            'booking_type_id' => $type->id,
            'instructor_id' => $instructor->id,
            'student_id' => User::factory()->create(['status' => 'active'])->id,
            'starts_at' => now()->subHours(3),
            'ends_at' => now()->subHours(2),
            'payment_status' => BookingPaymentStatus::Paid,
            'price' => '4321.99',
            'currency' => 'INR',
        ]);

        $this->actingAs($admin)
            ->get(BookingLessonMeetingOperations::getUrl())
            ->assertOk()
            ->assertDontSee('4321.99')
            ->assertDontSee('₹');
    }

    // ── Registry integration ──────────────────────────────────────────────

    public function test_report_is_registered_available_with_a_real_route(): void
    {
        $definition = app(ReportRegistryInterface::class)->find('booking_lesson_meeting_operations');

        $this->assertNotNull($definition);
        $this->assertTrue($definition->available);
        $this->assertSame(BookingLessonMeetingOperations::class, $definition->routeName);
    }

    public function test_reporting_hub_lists_the_operations_report_as_available(): void
    {
        $this->actingAs($this->manager())
            ->get(ReportingHub::getUrl())
            ->assertOk()
            ->assertSee('Booking, Lesson &amp; Meeting Operations', false);
    }

    // ── Coexistence with pre-existing pages ───────────────────────────────

    public function test_existing_booking_reports_page_still_works(): void
    {
        $this->seed(BookingPermissionSeeder::class);
        $admin = $this->manager();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($admin)
            ->get(BookingReports::getUrl())
            ->assertOk();
    }

    public function test_existing_booking_reports_csv_export_still_works(): void
    {
        $this->seed(BookingPermissionSeeder::class);
        $admin = $this->manager();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // The export action itself streams a CSV; reaching the page that
        // hosts it (and the analytics service behind it) is the regression
        // guard here — the CSV pipeline is covered by its own suite.
        $this->actingAs($admin)
            ->get(BookingReports::getUrl())
            ->assertOk()
            ->assertSee('Export KPIs (CSV)');
    }
}
