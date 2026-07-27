<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Enums\StudentStatus;
use App\Homework\Enums\HomeworkStatus;
use App\Livewire\Frontend\Student\HomeworkList;
use App\Models\HomeworkAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HomeworkListTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $this->student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->student->assignRole('student');
        $this->student->profile()->update(['student_status' => StudentStatus::Active]); // interactive student actions require Active status.
        $this->teacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
    }

    public function test_page_renders_the_livewire_component(): void
    {
        $this->actingAs($this->student)
            ->get(route('dashboard.homework'))
            ->assertOk()
            ->assertSeeLivewire(HomeworkList::class);
    }

    public function test_guest_is_redirected(): void
    {
        $this->get(route('dashboard.homework'))->assertRedirect(route('auth.login'));
    }

    public function test_shows_empty_state_with_no_assignments(): void
    {
        Livewire::actingAs($this->student)
            ->test(HomeworkList::class)
            ->assertSee('No homework assigned');
    }

    public function test_lists_only_own_assignments(): void
    {
        $mine = HomeworkAssignment::factory()->create([
            'student_id' => $this->student->id,
            'teacher_id' => $this->teacher->id,
            'title' => 'Algebra Worksheet',
        ]);

        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        HomeworkAssignment::factory()->create([
            'student_id' => $other->id,
            'teacher_id' => $this->teacher->id,
            'title' => 'Chemistry Notes',
        ]);

        Livewire::actingAs($this->student)
            ->test(HomeworkList::class)
            ->assertSee('Algebra Worksheet')
            ->assertDontSee('Chemistry Notes');
    }

    public function test_student_can_submit_pending_homework(): void
    {
        $assignment = HomeworkAssignment::factory()->create([
            'student_id' => $this->student->id,
            'teacher_id' => $this->teacher->id,
            'status' => HomeworkStatus::Pending,
        ]);

        Livewire::actingAs($this->student)
            ->test(HomeworkList::class)
            ->call('startSubmission', $assignment->id)
            ->set('submissionText', 'Here is my completed answer.')
            ->call('submit')
            ->assertHasNoErrors();

        $assignment->refresh();
        $this->assertSame(HomeworkStatus::Submitted, $assignment->status);
        $this->assertSame('Here is my completed answer.', $assignment->submission_text);
        $this->assertNotNull($assignment->submitted_at);
    }

    public function test_submit_requires_non_empty_text(): void
    {
        $assignment = HomeworkAssignment::factory()->create([
            'student_id' => $this->student->id,
            'teacher_id' => $this->teacher->id,
            'status' => HomeworkStatus::Pending,
        ]);

        Livewire::actingAs($this->student)
            ->test(HomeworkList::class)
            ->call('startSubmission', $assignment->id)
            ->set('submissionText', '')
            ->call('submit')
            ->assertHasErrors(['submissionText' => 'required']);

        $this->assertSame(HomeworkStatus::Pending, $assignment->fresh()->status);
    }

    public function test_another_students_assignment_cannot_be_submitted(): void
    {
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $assignment = HomeworkAssignment::factory()->create([
            'student_id' => $other->id,
            'teacher_id' => $this->teacher->id,
            'status' => HomeworkStatus::Pending,
        ]);

        Livewire::actingAs($this->student)
            ->test(HomeworkList::class)
            ->call('startSubmission', $assignment->id)
            ->assertForbidden();
    }

    public function test_already_submitted_homework_cannot_be_resubmitted(): void
    {
        $assignment = HomeworkAssignment::factory()->submitted()->create([
            'student_id' => $this->student->id,
            'teacher_id' => $this->teacher->id,
        ]);

        $component = Livewire::actingAs($this->student)->test(HomeworkList::class);

        $component->set('submittingId', $assignment->id)
            ->set('submissionText', 'Second attempt')
            ->call('submit')
            ->assertHasErrors('submissionText');
    }
}
