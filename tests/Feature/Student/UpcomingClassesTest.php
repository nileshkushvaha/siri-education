<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Booking\Enums\BookingStatus;
use App\Livewire\Frontend\Student\UpcomingClasses;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UpcomingClassesTest extends TestCase
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
            ->get(route('dashboard.upcoming-classes'))
            ->assertOk()
            ->assertSeeLivewire(UpcomingClasses::class);
    }

    public function test_guest_is_redirected(): void
    {
        $this->get(route('dashboard.upcoming-classes'))->assertRedirect(route('auth.login'));
    }

    public function test_shows_empty_state_with_no_bookings(): void
    {
        Livewire::actingAs($this->student)
            ->test(UpcomingClasses::class)
            ->assertSee('No upcoming classes');
    }

    public function test_lists_only_own_active_upcoming_bookings(): void
    {
        $type = BookingType::factory()->create(['name' => 'Maths Tutoring']);
        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $mine = Booking::factory()->create([
            'booking_type_id' => $type->id,
            'student_id' => $this->student->id,
            'instructor_id' => $teacher->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHour(),
        ]);

        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Booking::factory()->create([
            'booking_type_id' => $type->id,
            'student_id' => $other->id,
            'instructor_id' => $teacher->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addHour(),
        ]);

        Livewire::actingAs($this->student)
            ->test(UpcomingClasses::class)
            ->assertSee('Maths Tutoring')
            ->assertSee($teacher->name)
            ->assertViewHas('classes', fn ($classes) => $classes->pluck('id')->contains($mine->id) && $classes->count() === 1);
    }

    public function test_does_not_list_past_or_cancelled_bookings(): void
    {
        $type = BookingType::factory()->create();
        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        Booking::factory()->cancelled()->create([
            'booking_type_id' => $type->id,
            'student_id' => $this->student->id,
            'instructor_id' => $teacher->id,
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHour(),
        ]);

        Livewire::actingAs($this->student)
            ->test(UpcomingClasses::class)
            ->assertSee('No upcoming classes');
    }
}
