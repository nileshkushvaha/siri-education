<?php

declare(strict_types=1);

namespace Tests\Feature\Instructor;

use App\Enums\InstructorStatus;
use App\Enums\LearningGoalStatus;
use App\Enums\LearningPlanStatus;
use App\Lessons\Enums\LessonStatus;
use App\Livewire\Frontend\Instructor\StudentDetail;
use App\Livewire\Frontend\Instructor\StudentList;
use App\Models\AcademicCategory;
use App\Models\Booking;
use App\Models\Lesson;
use App\Models\StudentLearningGoal;
use App\Models\StudentLearningPlan;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class InstructorStudentManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        $this->instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->instructor->assignRole('instructor');
        $this->instructor->profile()->update(['instructor_status' => InstructorStatus::Active]);

        $this->student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->student->assignRole('student');
    }

    // ── Access ────────────────────────────────────────────────────────

    public function test_instructor_can_view_own_student_roster(): void
    {
        $this->actingAs($this->instructor)
            ->get(route('dashboard.instructor.students'))
            ->assertOk()
            ->assertSeeLivewire(StudentList::class);
    }

    public function test_student_cannot_access_instructor_roster_page(): void
    {
        $this->actingAs($this->student)
            ->get(route('dashboard.instructor.students'))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_from_roster_page(): void
    {
        $this->get(route('dashboard.instructor.students'))->assertRedirect(route('auth.login'));
    }

    // ── Ownership ─────────────────────────────────────────────────────

    public function test_instructor_sees_their_own_students(): void
    {
        $this->makeLesson($this->instructor, $this->student, ['status' => LessonStatus::Completed]);

        $response = $this->actingAs($this->instructor)->get(route('dashboard.instructor.students'))->assertOk();

        $response->assertSee($this->student->name);
    }

    public function test_instructor_cannot_see_another_instructors_students(): void
    {
        $otherInstructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $otherInstructor->assignRole('instructor');
        $otherStudent = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $otherStudent->assignRole('student');
        $this->makeLesson($otherInstructor, $otherStudent);

        $response = $this->actingAs($this->instructor)->get(route('dashboard.instructor.students'))->assertOk();

        $response->assertDontSee($otherStudent->name);
    }

    public function test_instructor_cannot_view_detail_page_for_a_student_they_never_taught(): void
    {
        $otherInstructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $otherInstructor->assignRole('instructor');
        $this->makeLesson($otherInstructor, $this->student);

        // Scoped-ownership lookup (Phase 23K precedent): a student the
        // instructor never taught simply doesn't exist in their roster.
        $this->actingAs($this->instructor)
            ->get(route('dashboard.instructor.students.show', $this->student))
            ->assertNotFound();
    }

    public function test_instructor_can_view_detail_page_for_their_own_student(): void
    {
        $this->makeLesson($this->instructor, $this->student, ['status' => LessonStatus::Completed]);

        $this->actingAs($this->instructor)
            ->get(route('dashboard.instructor.students.show', $this->student))
            ->assertOk()
            ->assertSeeLivewire(StudentDetail::class);
    }

    // ── Pagination ────────────────────────────────────────────────────

    public function test_roster_is_paginated(): void
    {
        foreach (range(1, 25) as $i) {
            $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
            $student->assignRole('student');
            $this->makeLesson($this->instructor, $student);
        }

        Livewire::actingAs($this->instructor)
            ->test(StudentList::class)
            ->assertViewHas('students', fn ($students) => $students->count() === 20 && $students->hasMorePages());
    }

    public function test_roster_query_count_is_bounded_as_students_grow(): void
    {
        foreach (range(1, 5) as $i) {
            $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
            $student->assignRole('student');
            $this->makeLesson($this->instructor, $student);
        }
        $initial = $this->queryCountForRosterPage();

        foreach (range(1, 15) as $i) {
            $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
            $student->assignRole('student');
            $this->makeLesson($this->instructor, $student);
        }
        $grown = $this->queryCountForRosterPage();

        $this->assertLessThanOrEqual($initial + 2, $grown, 'Roster page queries must stay bounded (grouped aggregate + 2 hydration lookups), not scale with student count.');
    }

    private function queryCountForRosterPage(): int
    {
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });
        $this->actingAs($this->instructor)->get(route('dashboard.instructor.students'))->assertOk();

        return $queries;
    }

    // ── Privacy ───────────────────────────────────────────────────────

    public function test_roster_never_exposes_student_email(): void
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE, 'email' => 'private-roster-student@example.test']);
        $student->assignRole('student');
        $this->makeLesson($this->instructor, $student);

        $response = $this->actingAs($this->instructor)->get(route('dashboard.instructor.students'))->assertOk();

        $response->assertDontSee('private-roster-student@example.test');
    }

    public function test_detail_page_never_exposes_student_email_or_payment_data(): void
    {
        $this->makeLesson($this->instructor, $this->student, ['status' => LessonStatus::Completed]);
        $this->student->update(['email' => 'detail-private@example.test']);

        $response = $this->actingAs($this->instructor)->get(route('dashboard.instructor.students.show', $this->student))->assertOk();

        $response->assertDontSee('detail-private@example.test');
        $response->assertDontSee('wallet', false);
        $response->assertDontSee('payment', false);
    }

    // ── Empty states ──────────────────────────────────────────────────

    public function test_empty_roster_state_is_shown(): void
    {
        Livewire::actingAs($this->instructor)
            ->test(StudentList::class)
            ->assertSee("You don't have any students yet.");
    }

    public function test_detail_page_shows_no_learning_plan_state(): void
    {
        $this->makeLesson($this->instructor, $this->student, ['status' => LessonStatus::Completed]);

        Livewire::actingAs($this->instructor)
            ->test(StudentDetail::class, ['student' => $this->student])
            ->assertSee('No active learning plan.');
    }

    // ── Learning plan status surfaces correctly ─────────────────────

    public function test_learning_plan_status_appears_on_roster_and_detail(): void
    {
        $this->makeLesson($this->instructor, $this->student, ['status' => LessonStatus::Completed]);

        $category = AcademicCategory::query()->firstOrCreate(['slug' => 'general'], ['name' => 'General']);
        $subject = Subject::query()->firstOrCreate(['slug' => 'maths'], ['academic_category_id' => $category->id, 'name' => 'Maths', 'status' => 'active']);
        $goal = StudentLearningGoal::query()->create([
            'user_id' => $this->student->id,
            'subject_id' => $subject->id,
            'title' => 'Master algebra',
            'type' => 'academic',
            'status' => LearningGoalStatus::Active,
        ]);

        StudentLearningPlan::query()->create([
            'student_user_id' => $this->student->id,
            'learning_goal_id' => $goal->id,
            'primary_instructor_user_id' => $this->instructor->id,
            'subject_id' => $subject->id,
            'title' => 'Algebra plan',
            'status' => LearningPlanStatus::Active,
            'progress_percent' => 40,
            'started_at' => now()->subDays(5),
        ]);

        $rosterResponse = $this->actingAs($this->instructor)->get(route('dashboard.instructor.students'))->assertOk();
        $rosterResponse->assertSee('Active');

        $detailResponse = $this->actingAs($this->instructor)->get(route('dashboard.instructor.students.show', $this->student))->assertOk();
        $detailResponse->assertSee('Active');
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /** @param array<string, mixed> $overrides */
    private function makeLesson(User $instructor, User $student, array $overrides = []): Lesson
    {
        $booking = Booking::factory()->confirmed()->create([
            'instructor_id' => $instructor->id,
            'student_id' => $student->id,
        ]);

        return Lesson::factory()->create([
            'booking_id' => $booking->id,
            'instructor_id' => $instructor->id,
            'student_id' => $student->id,
            ...$overrides,
        ]);
    }
}
