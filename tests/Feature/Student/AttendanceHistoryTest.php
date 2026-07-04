<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Booking\Enums\BookingStatus;
use App\Livewire\Frontend\Student\AttendanceHistory;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AttendanceHistoryTest extends TestCase
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
            ->get(route('dashboard.attendance'))
            ->assertOk()
            ->assertSeeLivewire(AttendanceHistory::class);
    }

    public function test_guest_is_redirected(): void
    {
        $this->get(route('dashboard.attendance'))->assertRedirect(route('auth.login'));
    }

    public function test_computes_completed_and_no_show_stats(): void
    {
        $type = BookingType::factory()->create();
        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        Booking::factory()->completed()->create([
            'booking_type_id' => $type->id,
            'attendee_id' => $this->student->id,
            'host_id' => $teacher->id,
        ]);
        Booking::factory()->create([
            'booking_type_id' => $type->id,
            'attendee_id' => $this->student->id,
            'host_id' => $teacher->id,
            'status' => BookingStatus::NoShow,
        ]);

        Livewire::actingAs($this->student)
            ->test(AttendanceHistory::class)
            ->assertViewHas('stats', fn ($stats) => (int) $stats->completed === 1 && (int) $stats->no_show === 1);
    }
}
