<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Booking\Enums\BookingStatus;
use App\Livewire\Frontend\Student\BookingHistory;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BookingHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $this->student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->student->assignRole('student');
    }

    public function test_page_renders_the_livewire_component(): void
    {
        $this->actingAs($this->student)
            ->get(route('dashboard.my-bookings'))
            ->assertOk()
            ->assertSeeLivewire(BookingHistory::class);
    }

    public function test_guest_is_redirected(): void
    {
        $this->get(route('dashboard.my-bookings'))->assertRedirect(route('auth.login'));
    }

    public function test_lists_own_bookings_of_any_status(): void
    {
        $type = BookingType::factory()->create(['name' => 'Physics Tutoring']);
        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        Booking::factory()->completed()->create([
            'booking_type_id' => $type->id,
            'attendee_id' => $this->student->id,
            'host_id' => $teacher->id,
        ]);

        Livewire::actingAs($this->student)
            ->test(BookingHistory::class)
            ->assertSee('Physics Tutoring');
    }

    public function test_status_filter_narrows_results(): void
    {
        $type = BookingType::factory()->create();
        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        Booking::factory()->completed()->create([
            'booking_type_id' => $type->id,
            'attendee_id' => $this->student->id,
            'host_id' => $teacher->id,
        ]);
        Booking::factory()->cancelled()->create([
            'booking_type_id' => $type->id,
            'attendee_id' => $this->student->id,
            'host_id' => $teacher->id,
        ]);

        Livewire::actingAs($this->student)
            ->test(BookingHistory::class)
            ->set('statusFilter', BookingStatus::Cancelled->value)
            ->assertViewHas('history', fn ($history) => $history->total() === 1
                && $history->first()->status === BookingStatus::Cancelled);
    }

    public function test_does_not_show_other_students_bookings(): void
    {
        $type = BookingType::factory()->create();
        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        Booking::factory()->completed()->create([
            'booking_type_id' => $type->id,
            'attendee_id' => $other->id,
            'host_id' => $teacher->id,
        ]);

        Livewire::actingAs($this->student)
            ->test(BookingHistory::class)
            ->assertSee('No bookings found');
    }
}
