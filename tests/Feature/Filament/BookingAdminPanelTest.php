<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Models\BookingType;
use App\Models\TeacherAvailability;
use App\Models\TeacherUnavailability;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BookingAdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // super_admin bypasses all policies via Gate::before()
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole('super_admin');
    }

    public function test_booking_resource_pages_render(): void
    {
        BookingType::factory()->count(2)->create();

        $this->actingAs($this->admin)->get('/admin/bookings')->assertOk();
        $this->actingAs($this->admin)->get('/admin/booking-types')->assertOk();
        $this->actingAs($this->admin)->get('/admin/booking-types/create')->assertOk();
    }

    public function test_teacher_resources_render(): void
    {
        TeacherAvailability::factory()->create();
        TeacherUnavailability::factory()->create();

        $this->actingAs($this->admin)->get('/admin/teacher-availability')->assertOk();
        $this->actingAs($this->admin)->get('/admin/teacher-availability/create')->assertOk();
        $this->actingAs($this->admin)->get('/admin/teacher-leave')->assertOk();
        $this->actingAs($this->admin)->get('/admin/teacher-leave/create')->assertOk();
    }

    public function test_booking_reports_page_renders_with_widgets(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/booking-reports')
            ->assertOk()
            ->assertSee('Booking Reports');
    }

    public function test_panel_denies_users_without_permissions(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $this->actingAs($user)->get('/admin/bookings')->assertForbidden();
        $this->actingAs($user)->get('/admin/booking-reports')->assertForbidden();
    }
}
