<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Booking\Enums\BookingStatus;
use App\Enums\InstructorStatus;
use App\Enums\LearningGoalStatus;
use App\Enums\LearningPlanStatus;
use App\Enums\StudentStatus;
use App\Homework\Enums\HomeworkStatus;
use App\Livewire\Frontend\Instructor\HomeworkList;
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
 * Instructor homework assignment UI.
 * Lesson/plan options are scoped to the instructor's own students;
 * at least one context is mandatory; selections survive validation
 * failure; unauthorized actors never reach the form.
 */
final class InstructorHomeworkAssignTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);

        app(FeatureSettings::class)->homework_enabled = true;

        $this->instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->instructor->assignRole('instructor');
        $this->instructor->profile()->update(['instructor_status' => InstructorStatus::Active]);

        $this->student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->student->assignRole('student');
        $this->student->profile()->update(['student_status' => StudentStatus::Active]);
    }

    public function test_authorized_instructor_can_assign_homework_linked_to_a_completed_lesson(): void
    {
        $booking = $this->completedBooking();

        Livewire::actingAs($this->instructor)
            ->test(HomeworkList::class)
            ->call('openAssignForm')
            ->set('assignStudentId', $this->student->id)
            ->set('assignBookingId', $booking->id)
            ->set('assignTitle', 'Fractions worksheet')
            ->set('assignSubject', 'maths')
            ->set('assignDueAt', now()->addWeek()->format('Y-m-d\TH:i'))
            ->call('assign')
            ->assertHasNoErrors();

        $assignment = HomeworkAssignment::query()->sole();
        $this->assertSame($booking->id, $assignment->booking_id);
        $this->assertSame($this->student->id, $assignment->student_id);
        $this->assertSame($this->instructor->id, $assignment->teacher_id);
        $this->assertSame(HomeworkStatus::Pending, $assignment->status);
    }

    public function test_assign_without_lesson_or_plan_shows_error_and_preserves_entries(): void
    {
        Livewire::actingAs($this->instructor)
            ->test(HomeworkList::class)
            ->call('openAssignForm')
            ->set('assignStudentId', $this->student->id)
            ->set('assignTitle', 'Fractions worksheet')
            ->set('assignSubject', 'maths')
            ->set('assignDueAt', now()->addWeek()->format('Y-m-d\TH:i'))
            ->call('assign')
            ->assertHasErrors('assignContext')
            // Step 9: entries survive the failure for correction.
            ->assertSet('assignTitle', 'Fractions worksheet')
            ->assertSet('assignStudentId', $this->student->id)
            ->assertSet('showAssignForm', true);

        $this->assertSame(0, HomeworkAssignment::query()->count());
    }

    public function test_options_are_scoped_to_the_instructor_without_cross_student_leakage(): void
    {
        $booking = $this->completedBooking();
        $plan = $this->plan($this->student, $this->instructor);

        // Foreign relationships that must never be listed: another
        // instructor's student, and another student's plan.
        $otherInstructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $otherInstructor->assignRole('instructor');
        $foreignStudent = User::factory()->create(['status' => User::STATUS_ACTIVE, 'name' => 'Foreign Student']);
        $foreignStudent->assignRole('student');
        $foreignStudent->profile()->update(['student_status' => StudentStatus::Active]);
        $foreignBooking = Booking::factory()->completed()->create([
            'instructor_id' => $otherInstructor->id,
            'student_id' => $foreignStudent->id,
        ]);
        $foreignPlan = $this->plan($foreignStudent, $otherInstructor);

        $component = Livewire::actingAs($this->instructor)
            ->test(HomeworkList::class)
            ->call('openAssignForm')
            ->set('assignStudentId', $this->student->id);

        $studentOptions = $component->viewData('studentOptions');
        $bookingOptions = $component->viewData('bookingOptions');
        $planOptions = $component->viewData('planOptions');

        $this->assertTrue($studentOptions->has($this->student->id));
        $this->assertFalse($studentOptions->has($foreignStudent->id));
        $this->assertTrue($bookingOptions->has($booking->id));
        $this->assertFalse($bookingOptions->has($foreignBooking->id));
        $this->assertTrue($planOptions->has($plan->id));
        $this->assertFalse($planOptions->has($foreignPlan->id));
    }

    public function test_only_completed_lessons_and_writable_plans_are_offered(): void
    {
        $confirmed = Booking::factory()->confirmed()->create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
        ]);
        $completed = $this->completedBooking();
        $archivedPlan = $this->plan($this->student, $this->instructor, LearningPlanStatus::Archived);
        $activePlan = $this->plan($this->student, $this->instructor);

        $component = Livewire::actingAs($this->instructor)
            ->test(HomeworkList::class)
            ->call('openAssignForm')
            ->set('assignStudentId', $this->student->id);

        $this->assertTrue($component->viewData('bookingOptions')->has($completed->id));
        $this->assertFalse($component->viewData('bookingOptions')->has($confirmed->id));
        $this->assertTrue($component->viewData('planOptions')->has($activePlan->id));
        $this->assertFalse($component->viewData('planOptions')->has($archivedPlan->id));
    }

    public function test_forged_foreign_booking_id_is_rejected_by_the_service(): void
    {
        // Scoped options are presentation; the service is the authority.
        $otherInstructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $otherInstructor->assignRole('instructor');
        $foreignBooking = Booking::factory()->completed()->create([
            'instructor_id' => $otherInstructor->id,
            'student_id' => $this->student->id,
        ]);

        Livewire::actingAs($this->instructor)
            ->test(HomeworkList::class)
            ->call('openAssignForm')
            ->set('assignStudentId', $this->student->id)
            ->set('assignBookingId', $foreignBooking->id)
            ->set('assignTitle', 'Forged context')
            ->set('assignSubject', 'maths')
            ->set('assignDueAt', now()->addWeek()->format('Y-m-d\TH:i'))
            ->call('assign')
            ->assertHasErrors('assignContext');

        $this->assertSame(0, HomeworkAssignment::query()->count());
    }

    public function test_plan_archived_after_selection_surfaces_a_validation_error(): void
    {
        $plan = $this->plan($this->student, $this->instructor);

        $component = Livewire::actingAs($this->instructor)
            ->test(HomeworkList::class)
            ->call('openAssignForm')
            ->set('assignStudentId', $this->student->id)
            ->set('assignPlanId', $plan->id)
            ->set('assignTitle', 'Race case')
            ->set('assignSubject', 'maths')
            ->set('assignDueAt', now()->addWeek()->format('Y-m-d\TH:i'));

        $plan->forceFill(['status' => LearningPlanStatus::Archived, 'archived_at' => now()])->save();

        $component->call('assign')->assertHasErrors('assignContext');

        $this->assertSame(0, HomeworkAssignment::query()->count());
    }

    public function test_student_cannot_invoke_the_assign_action(): void
    {
        Livewire::actingAs($this->student)
            ->test(HomeworkList::class)
            ->call('openAssignForm')
            ->assertForbidden();
    }

    public function test_admin_access_to_instructor_homework_remains_unchanged(): void
    {
        // Homework assignment has no admin surface: an admin-portal user
        // is redirected away from the frontend instructor homework page
        // by the portal middleware.
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole('manager');

        $response = $this->actingAs($manager)->get(route('dashboard.instructor.homework'));

        $response->assertRedirect();
        $this->assertStringNotContainsString('Assign Homework', (string) $response->getContent());
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    private function completedBooking(): Booking
    {
        return Booking::factory()->completed()->create([
            'instructor_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'status' => BookingStatus::Completed,
        ]);
    }

    private function plan(User $student, User $instructor, LearningPlanStatus $status = LearningPlanStatus::Active): StudentLearningPlan
    {
        $category = AcademicCategory::query()->firstOrCreate(['slug' => 'general'], ['name' => 'General']);
        $subject = Subject::query()->firstOrCreate(
            ['slug' => 'maths'],
            ['academic_category_id' => $category->id, 'name' => 'Maths', 'status' => 'active'],
        );

        $goal = StudentLearningGoal::query()->create([
            'user_id' => $student->id,
            'subject_id' => $subject->id,
            'title' => 'Master algebra',
            'type' => 'academic',
            'status' => LearningGoalStatus::Active,
        ]);

        return StudentLearningPlan::query()->create([
            'student_user_id' => $student->id,
            'learning_goal_id' => $goal->id,
            'primary_instructor_user_id' => $instructor->id,
            'subject_id' => $subject->id,
            'title' => 'Algebra plan',
            'status' => $status,
            'progress_percent' => 0,
        ]);
    }
}
