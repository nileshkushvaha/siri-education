<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Booking\Enums\BookingStatus;
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
            ->assertSeeLivewire(DashboardOverview::class);
    }

    public function test_instructor_dashboard_renders_instructor_overview_component(): void
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');

        $this->actingAs($instructor)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeLivewire(InstructorDashboardOverview::class)
            ->assertDontSeeLivewire(DashboardOverview::class);
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

    public function test_stats_reflect_upcoming_classes_and_pending_homework(): void
    {
        $type = BookingType::factory()->create(['name' => 'English Tutoring']);
        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        Booking::factory()->create([
            'booking_type_id' => $type->id,
            'attendee_id' => $this->student->id,
            'host_id' => $teacher->id,
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
            ->assertSet('upcomingCount', 1)
            ->assertSet('overdueHomeworkCount', 1);
    }
}
