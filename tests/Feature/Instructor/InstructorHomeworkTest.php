<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Enums\InstructorStatus;
use App\Homework\Enums\HomeworkStatus;
use App\Livewire\Frontend\Instructor\HomeworkList;
use App\Models\HomeworkAssignment;
use App\Models\User;
use App\Settings\FeatureSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class InstructorHomeworkTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        app(FeatureSettings::class)->homework_enabled = true;

        $this->instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->instructor->assignRole('instructor');
        $this->instructor->profile()->update(['instructor_status' => InstructorStatus::Active]);

        $this->student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->student->assignRole('student');
    }

    // ── Access control ───────────────────────────────────────────────

    public function test_instructor_can_view_the_homework_page(): void
    {
        $this->actingAs($this->instructor)
            ->get(route('dashboard.instructor.homework'))
            ->assertOk()
            ->assertSeeLivewire(HomeworkList::class);
    }

    public function test_student_cannot_view_the_instructor_homework_page(): void
    {
        $this->actingAs($this->student)
            ->get(route('dashboard.instructor.homework'))
            ->assertForbidden();
    }

    public function test_guest_is_redirected(): void
    {
        $this->get(route('dashboard.instructor.homework'))->assertRedirect(route('auth.login'));
    }

    public function test_instructor_only_sees_their_own_submissions(): void
    {
        $mine = HomeworkAssignment::factory()->submitted()->create([
            'teacher_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'title' => 'Algebra Worksheet',
        ]);

        $otherTeacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $otherTeacher->assignRole('instructor');
        HomeworkAssignment::factory()->submitted()->create([
            'teacher_id' => $otherTeacher->id,
            'student_id' => $this->student->id,
            'title' => 'Chemistry Notes',
        ]);

        Livewire::actingAs($this->instructor)
            ->test(HomeworkList::class)
            ->assertSee('Algebra Worksheet')
            ->assertDontSee('Chemistry Notes');
    }

    public function test_instructor_cannot_review_another_teachers_assignment(): void
    {
        $otherTeacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $otherTeacher->assignRole('instructor');
        $assignment = HomeworkAssignment::factory()->submitted()->create([
            'teacher_id' => $otherTeacher->id,
            'student_id' => $this->student->id,
        ]);

        Livewire::actingAs($this->instructor)
            ->test(HomeworkList::class)
            ->call('startReview', $assignment->id)
            ->assertForbidden();
    }

    // ── Submission review workflow ───────────────────────────────────

    public function test_instructor_can_review_a_submitted_assignment(): void
    {
        $assignment = HomeworkAssignment::factory()->submitted()->create([
            'teacher_id' => $this->instructor->id,
            'student_id' => $this->student->id,
        ]);

        Livewire::actingAs($this->instructor)
            ->test(HomeworkList::class)
            ->call('startReview', $assignment->id)
            ->set('feedbackText', 'Great work, minor errors in step 3.')
            ->set('grade', 'A-')
            ->call('submitReview')
            ->assertHasNoErrors();

        $assignment->refresh();
        $this->assertSame(HomeworkStatus::Graded, $assignment->status);
        $this->assertSame('Great work, minor errors in step 3.', $assignment->feedback);
        $this->assertSame('A-', $assignment->grade);
    }

    public function test_review_requires_non_empty_feedback(): void
    {
        $assignment = HomeworkAssignment::factory()->submitted()->create([
            'teacher_id' => $this->instructor->id,
            'student_id' => $this->student->id,
        ]);

        Livewire::actingAs($this->instructor)
            ->test(HomeworkList::class)
            ->call('startReview', $assignment->id)
            ->set('feedbackText', '')
            ->call('submitReview')
            ->assertHasErrors(['feedbackText' => 'required']);

        $this->assertSame(HomeworkStatus::Submitted, $assignment->fresh()->status);
    }

    public function test_grade_is_optional(): void
    {
        $assignment = HomeworkAssignment::factory()->submitted()->create([
            'teacher_id' => $this->instructor->id,
            'student_id' => $this->student->id,
        ]);

        Livewire::actingAs($this->instructor)
            ->test(HomeworkList::class)
            ->call('startReview', $assignment->id)
            ->set('feedbackText', 'Solid effort.')
            ->set('grade', '')
            ->call('submitReview')
            ->assertHasNoErrors();

        $assignment->refresh();
        $this->assertSame(HomeworkStatus::Graded, $assignment->status);
        $this->assertNull($assignment->grade);
    }

    public function test_already_graded_homework_cannot_be_reviewed_again(): void
    {
        $assignment = HomeworkAssignment::factory()->graded()->create([
            'teacher_id' => $this->instructor->id,
            'student_id' => $this->student->id,
        ]);

        $component = Livewire::actingAs($this->instructor)->test(HomeworkList::class);

        $component->set('reviewingId', $assignment->id)
            ->set('feedbackText', 'Second review attempt')
            ->call('submitReview')
            ->assertHasErrors('feedbackText');
    }

    public function test_pending_homework_cannot_be_reviewed_before_submission(): void
    {
        $assignment = HomeworkAssignment::factory()->create([
            'teacher_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'status' => HomeworkStatus::Pending,
        ]);

        $component = Livewire::actingAs($this->instructor)->test(HomeworkList::class);

        $component->set('reviewingId', $assignment->id)
            ->set('feedbackText', 'Too early')
            ->call('submitReview')
            ->assertHasErrors('feedbackText');

        $this->assertSame(HomeworkStatus::Pending, $assignment->fresh()->status);
    }

    // ── Dashboard integration ────────────────────────────────────────

    public function test_dashboard_shows_a_working_review_link_when_submissions_are_pending(): void
    {
        HomeworkAssignment::factory()->submitted()->create([
            'teacher_id' => $this->instructor->id,
            'student_id' => $this->student->id,
        ]);
        $this->instructor->profile()->update(['instructor_status' => InstructorStatus::Active]);

        $response = $this->actingAs($this->instructor->fresh())->get(route('dashboard'))->assertOk();

        $response->assertSee('waiting for review');
        $response->assertSee(route('dashboard.instructor.homework'), false);
    }

    public function test_dashboard_shows_a_zero_state_when_no_submissions_are_pending(): void
    {
        $this->instructor->profile()->update(['instructor_status' => InstructorStatus::Active]);

        $response = $this->actingAs($this->instructor->fresh())->get(route('dashboard'))->assertOk();

        $response->assertSee('Homework submissions');
        $response->assertDontSee('waiting for review');
    }

    // ── Privacy ───────────────────────────────────────────────────────

    public function test_homework_page_never_exposes_another_students_submission_text(): void
    {
        $otherTeacher = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $otherTeacher->assignRole('instructor');
        HomeworkAssignment::factory()->submitted()->create([
            'teacher_id' => $otherTeacher->id,
            'student_id' => $this->student->id,
            'submission_text' => 'Secret answer only the other teacher should see.',
        ]);

        Livewire::actingAs($this->instructor)
            ->test(HomeworkList::class)
            ->assertDontSee('Secret answer only the other teacher should see.');
    }
}
