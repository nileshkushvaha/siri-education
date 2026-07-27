<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\BookingTypes\Pages\CreateBookingType;
use App\Models\BookingType;
use App\Models\TeacherAvailability;
use App\Models\TeacherUnavailability;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

    public function test_booking_type_form_no_longer_exposes_price_or_currency_fields(): void
    {
        // Student-facing paid prices are managed
        // exclusively from Student Lesson Prices — the columns backing
        // these fields no longer exist on booking_types at all.
        $this->actingAs($this->admin);

        Livewire::test(CreateBookingType::class)
            ->assertFormFieldDoesNotExist('price')
            ->assertFormFieldDoesNotExist('currency');
    }

    public function test_paid_booking_type_saves_without_any_price_field(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateBookingType::class)
            ->fillForm([
                'key' => 'paid_one_to_one',
                'name' => 'Paid One To One',
                'duration_minutes' => 60,
                'is_paid' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('booking_types', ['key' => 'paid_one_to_one', 'is_paid' => true]);
    }

    public function test_free_booking_type_does_not_require_price(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateBookingType::class)
            ->fillForm([
                'key' => 'free_demo',
                'name' => 'Free Demo',
                'duration_minutes' => 30,
                'is_paid' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('booking_types', ['key' => 'free_demo']);
    }
}
