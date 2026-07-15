<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Livewire\Frontend\Student\ProgressOverview;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProgressOverviewTest extends TestCase
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
            ->get(route('dashboard.progress'))
            ->assertOk()
            ->assertSeeLivewire(ProgressOverview::class);
    }

    public function test_shows_subject_breakdown_for_completed_sessions(): void
    {
        $type = BookingType::factory()->create();
        $teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        Booking::factory()->completed()->create([
            'booking_type_id' => $type->id,
            'student_id' => $this->student->id,
            'instructor_id' => $teacher->id,
            'meta' => ['subject' => 'Mathematics'],
        ]);

        Livewire::actingAs($this->student)
            ->test(ProgressOverview::class)
            ->assertSee('Mathematics')
            ->assertViewHas('stats', fn ($stats) => (int) $stats->completed_sessions === 1);
    }

    public function test_shows_empty_state_with_no_completed_sessions(): void
    {
        Livewire::actingAs($this->student)
            ->test(ProgressOverview::class)
            ->assertSee('No progress yet');
    }
}
