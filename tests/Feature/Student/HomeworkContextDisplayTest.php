<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Enums\InstructorStatus;
use App\Enums\LearningGoalStatus;
use App\Enums\LearningPlanStatus;
use App\Enums\StudentStatus;
use App\Homework\Contracts\HomeworkServiceInterface;
use App\Livewire\Frontend\Student\HomeworkList;
use App\Models\AcademicCategory;
use App\Models\Booking;
use App\Models\HomeworkAssignment;
use App\Models\StudentLearningGoal;
use App\Models\StudentLearningPlan;
use App\Models\Subject;
use App\Models\User;
use App\Settings\FeatureSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The student homework list shows the
 * educational context (lesson and/or learning plan) with safe labels,
 * including historical context for archived plans, and legacy
 * booking-only records remain fully usable.
 */
final class HomeworkContextDisplayTest extends TestCase
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
        $this->student->profile()->update(['student_status' => StudentStatus::Active]);
    }

    public function test_learning_plan_only_assignment_appears_with_its_plan_label(): void
    {
        $plan = $this->plan();
        $this->assign(learningPlanId: $plan->id);

        Livewire::actingAs($this->student)
            ->test(HomeworkList::class)
            ->assertSee('Fractions worksheet')
            ->assertSee('Plan: Algebra plan');
    }

    public function test_both_linked_assignment_shows_lesson_and_plan_context(): void
    {
        $booking = $this->completedBooking();
        $plan = $this->plan();
        $this->assign(bookingId: $booking->id, learningPlanId: $plan->id);

        Livewire::actingAs($this->student)
            ->test(HomeworkList::class)
            ->assertSee('Lesson:')
            ->assertSee('Plan: Algebra plan');
    }

    public function test_archived_plan_context_remains_visible_with_status_label(): void
    {
        $plan = $this->plan();
        $this->assign(learningPlanId: $plan->id);

        $plan->forceFill(['status' => LearningPlanStatus::Archived, 'archived_at' => now()])->save();

        Livewire::actingAs($this->student)
            ->test(HomeworkList::class)
            ->assertSee('Plan: Algebra plan')
            ->assertSee('(Archived)');
    }

    public function test_legacy_booking_only_assignment_remains_visible_and_submittable(): void
    {
        $booking = $this->completedBooking();
        $assignment = $this->assign(bookingId: $booking->id);

        Livewire::actingAs($this->student)
            ->test(HomeworkList::class)
            ->assertSee('Fractions worksheet')
            ->call('startSubmission', $assignment->id)
            ->set('submissionText', 'My completed answers.')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertSame('submitted', $assignment->fresh()->status->value);
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    private function completedBooking(): Booking
    {
        return Booking::factory()->completed()->create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
        ]);
    }

    private function plan(): StudentLearningPlan
    {
        $category = AcademicCategory::query()->firstOrCreate(['slug' => 'general'], ['name' => 'General']);
        $subject = Subject::query()->firstOrCreate(
            ['slug' => 'maths'],
            ['academic_category_id' => $category->id, 'name' => 'Maths', 'status' => 'active'],
        );

        $goal = StudentLearningGoal::query()->create([
            'user_id' => $this->student->id,
            'subject_id' => $subject->id,
            'title' => 'Master algebra',
            'type' => 'academic',
            'status' => LearningGoalStatus::Active,
        ]);

        return StudentLearningPlan::query()->create([
            'student_user_id' => $this->student->id,
            'learning_goal_id' => $goal->id,
            'primary_instructor_user_id' => $this->instructor->id,
            'subject_id' => $subject->id,
            'title' => 'Algebra plan',
            'status' => LearningPlanStatus::Active,
            'progress_percent' => 0,
        ]);
    }

    private function assign(?string $bookingId = null, ?int $learningPlanId = null): HomeworkAssignment
    {
        return app(HomeworkServiceInterface::class)->assign(
            $this->instructor,
            $this->student,
            ['title' => 'Fractions worksheet', 'subject' => 'maths', 'due_at' => now()->addWeek()],
            bookingId: $bookingId,
            learningPlanId: $learningPlanId,
        );
    }
}
