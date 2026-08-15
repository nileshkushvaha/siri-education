<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Booking\Enums\BookingStatus;
use App\Booking\Types\FreeDemoType;
use App\Livewire\Frontend\Instructor\DashboardOverview as InstructorDashboardOverview;
use App\Livewire\Frontend\Student\DashboardOverview;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\HomeworkAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardOverviewTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        $this->student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->student->assignRole('student');
    }

    public function test_dashboard_renders_the_overview_component(): void
    {
        $this->actingAs($this->student)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeLivewire(DashboardOverview::class)
            ->assertSee('Book free demo')
            ->assertSee(route('booking.create', ['type' => FreeDemoType::KEY]), false);
    }

    public function test_instructor_dashboard_renders_instructor_overview_component(): void
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');

        $this->actingAs($instructor)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeLivewire(InstructorDashboardOverview::class)
            ->assertDontSeeLivewire(DashboardOverview::class)
            ->assertDontSee('Book free demo')
            ->assertDontSee('Book paid lesson');
    }

    public function test_student_dashboard_wins_when_user_also_has_instructor_role(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole(['student', 'instructor']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeLivewire(DashboardOverview::class)
            ->assertDontSeeLivewire(InstructorDashboardOverview::class);
    }

    public function test_dashboard_reflects_the_next_lesson_and_overdue_homework(): void
    {
        $type = BookingType::factory()->create(['name' => 'English Tutoring']);
        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        Booking::factory()->create([
            'booking_type_id' => $type->id,
            'student_id' => $this->student->id,
            'instructor_id' => $teacher->id,
            'status' => BookingStatus::Confirmed,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);

        HomeworkAssignment::factory()->overdue()->create([
            'student_id' => $this->student->id,
            'teacher_id' => $teacher->id,
        ]);

        Livewire::actingAs($this->student)
            ->test(DashboardOverview::class)
            ->assertSee('English Tutoring')
            ->assertSee('Your next lesson')
            ->assertSee('1 overdue');
    }

    public function test_new_student_is_guided_directly_to_free_demo_booking(): void
    {
        Livewire::actingAs($this->student)
            ->test(DashboardOverview::class)
            ->assertSee('Start with a free demo')
            ->assertSee('Your first demo with each instructor is free.')
            ->assertSee(route('booking.create', ['type' => FreeDemoType::KEY]), false);
    }

    public function test_student_with_completed_demo_is_guided_to_paid_lesson_and_can_try_another_instructor(): void
    {
        $demo = BookingType::factory()->create([
            'key' => FreeDemoType::KEY,
            'name' => 'Free Demo',
            'is_paid' => false,
        ]);

        Booking::factory()->create([
            'booking_type_id' => $demo->id,
            'student_id' => $this->student->id,
            'status' => BookingStatus::Completed,
            'completed_at' => now()->subDay(),
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDays(2)->addMinutes(30),
        ]);

        Livewire::actingAs($this->student)
            ->test(DashboardOverview::class)
            ->assertSee('Continue with a paid lesson')
            ->assertSee('Try another instructor')
            ->assertSee(route('booking.create', ['type' => 'paid_one_to_one']), false)
            ->assertSee(route('booking.create', ['type' => FreeDemoType::KEY]), false);

        $this->actingAs($this->student)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Book paid lesson')
            ->assertSee(route('booking.create', ['type' => 'paid_one_to_one']), false);
    }
}
