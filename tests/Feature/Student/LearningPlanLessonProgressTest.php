<?php

declare(strict_types=1);

namespace Tests\Feature\Student;

use App\Enums\InstructorStatus;
use App\Enums\LearningPlanMilestoneStatus;
use App\Enums\LearningPlanStatus;
use App\Enums\StudentStatus;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\Enums\LessonOutcome;
use App\Models\AcademicCategory;
use App\Models\AcademicLevel;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Lesson;
use App\Models\StudentLearningGoal;
use App\Models\StudentLearningPlan;
use App\Models\Subject;
use App\Models\User;
use App\Services\Student\LearningPlanProgressCalculator;
use App\Services\Student\LearningPlanService;
use Database\Seeders\LessonPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SRS §6.17.5 / §6.17.10 (GAP-023): lessons may be server-resolved
 * onto exactly one compatible active learning plan at creation time,
 * and finalized outcomes on plan-linked lessons feed
 * LearningPlanProgressCalculator's lessons domain. Review contribution
 * remains out of scope (untouched, documented data-model blocker).
 */
class LearningPlanLessonProgressTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $instructor;

    private Subject $subject;

    private AcademicLevel $level;

    private StudentLearningGoal $goal;

    protected function setUp(): void
    {
        parent::setUp();

        // Seeded first, before any other permission gets created/cached
        // in this test — Spatie's permission cache otherwise snapshots
        // a stale list when a later seed only forgets the cache after
        // granting, tripping "no permission named X" on lookups that
        // happen to run after an earlier, unrelated permission grant.
        $this->seed(LessonPermissionSeeder::class);

        foreach (['student', 'instructor', 'manager'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->student->assignRole('student');
        $this->student->profile()->update(['student_status' => StudentStatus::Active]);

        $this->instructor = $this->makeInstructor();

        $category = AcademicCategory::create(['name' => 'Mathematics', 'slug' => 'mathematics']);
        $this->subject = Subject::create(['academic_category_id' => $category->id, 'name' => 'Algebra', 'slug' => 'algebra']);
        $this->level = AcademicLevel::create(['name' => 'High School', 'slug' => 'high-school']);

        $this->goal = StudentLearningGoal::create([
            'user_id' => $this->student->id,
            'subject_id' => $this->subject->id,
            'academic_level_id' => $this->level->id,
            'title' => 'Master algebra',
            'type' => 'academic',
            'status' => 'active',
            'created_by' => $this->student->id,
            'updated_by' => $this->student->id,
        ]);
    }

    // ── Association scenarios ─────────────────────────────────────────

    public function test_compatible_lesson_links_to_the_unique_active_plan(): void
    {
        $plan = $this->assignedPlan();

        $lesson = $this->createLesson($this->student, $this->instructor, $this->subject);

        $this->assertSame($plan->id, $lesson->learning_plan_id);
    }

    public function test_no_matching_plan_leaves_the_lesson_unlinked(): void
    {
        // No plan exists at all yet.
        $lesson = $this->createLesson($this->student, $this->instructor, $this->subject);

        $this->assertNull($lesson->learning_plan_id);
    }

    public function test_another_students_plan_cannot_be_linked(): void
    {
        $this->assignedPlan();
        $otherStudent = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $otherStudent->assignRole('student');
        $otherStudent->profile()->update(['student_status' => StudentStatus::Active]);

        $lesson = $this->createLesson($otherStudent, $this->instructor, $this->subject);

        $this->assertNull($lesson->learning_plan_id);
    }

    public function test_subject_mismatch_cannot_be_linked(): void
    {
        $this->assignedPlan();
        $otherCategory = AcademicCategory::create(['name' => 'Science', 'slug' => 'science']);
        $otherSubject = Subject::create(['academic_category_id' => $otherCategory->id, 'name' => 'Physics', 'slug' => 'physics']);

        $lesson = $this->createLesson($this->student, $this->instructor, $otherSubject);

        $this->assertNull($lesson->learning_plan_id);
    }

    public function test_instructor_mismatch_cannot_be_linked(): void
    {
        $this->assignedPlan();
        $otherInstructor = $this->makeInstructor();

        $lesson = $this->createLesson($this->student, $otherInstructor, $this->subject);

        $this->assertNull($lesson->learning_plan_id);
    }

    public function test_completed_or_archived_plan_is_not_selected_for_a_new_lesson(): void
    {
        $plan = $this->assignedPlan();
        $milestone = app(LearningPlanService::class)->createMilestone($this->instructor, $plan, ['title' => 'Only']);
        app(LearningPlanService::class)->completeMilestone($this->instructor, $milestone);
        app(LearningPlanService::class)->completePlan($this->instructor, $plan->refresh());

        $lesson = $this->createLesson($this->student, $this->instructor, $this->subject);

        $this->assertNull($lesson->learning_plan_id);
    }

    public function test_multiple_candidate_plans_fail_safely_without_arbitrary_selection(): void
    {
        $this->assignedPlan();
        // A second goal for the SAME student+subject+instructor — this
        // project has no hard one-active-plan-per-subject constraint
        // (SRS: "should normally", not enforced), so ambiguity is real.
        $secondGoal = $this->newGoal('Second algebra goal');
        $this->assignedPlan($secondGoal);

        $lesson = $this->createLesson($this->student, $this->instructor, $this->subject);

        $this->assertNull($lesson->learning_plan_id);
    }

    public function test_ordinary_lesson_creation_succeeds_without_a_plan(): void
    {
        $booking = Booking::factory()->confirmed()->create();

        $lesson = app(LessonLifecycleServiceInterface::class)->createFromBooking($booking);

        $this->assertNotNull($lesson);
        $this->assertNull($lesson->learning_plan_id);
    }

    public function test_recurring_occurrences_are_handled_independently_and_consistently(): void
    {
        $plan = $this->assignedPlan();

        $first = $this->createLesson($this->student, $this->instructor, $this->subject);
        $second = $this->createLesson($this->student, $this->instructor, $this->subject);

        $this->assertSame($plan->id, $first->learning_plan_id);
        $this->assertSame($plan->id, $second->learning_plan_id);
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_a_crafted_client_plan_id_cannot_bypass_server_validation(): void
    {
        $otherStudentGoal = StudentLearningGoal::create([
            'user_id' => $this->student->id,
            'subject_id' => $this->subject->id,
            'academic_level_id' => $this->level->id,
            'title' => 'Unrelated goal',
            'type' => 'academic',
            'status' => 'active',
        ]);
        $foreignPlan = $this->assignedPlan($otherStudentGoal, withOtherInstructor: true);

        // An attacker-controlled booking meta cannot smuggle a plan id
        // in — CreateLessonFromBookingAction never reads any plan-id-
        // shaped key from meta; resolution is server-derived only.
        $booking = Booking::factory()->confirmed()->create([
            'booking_type_id' => BookingType::factory()->paid(),
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'meta' => ['subject' => $this->subject->slug, 'learning_plan_id' => $foreignPlan->id],
        ]);

        $lesson = app(LessonLifecycleServiceInterface::class)->createFromBooking($booking);

        $this->assertNotSame($foreignPlan->id, $lesson->learning_plan_id);
        $this->assertNull($lesson->learning_plan_id);
    }

    public function test_historical_association_remains_after_the_plan_completes_or_archives(): void
    {
        $plan = $this->assignedPlan();
        $lesson = $this->createLesson($this->student, $this->instructor, $this->subject);
        $this->assertSame($plan->id, $lesson->learning_plan_id);

        $milestone = app(LearningPlanService::class)->createMilestone($this->instructor, $plan, ['title' => 'Only']);
        app(LearningPlanService::class)->completeMilestone($this->instructor, $milestone);
        app(LearningPlanService::class)->completePlan($this->instructor, $plan->refresh());
        app(LearningPlanService::class)->archivePlan($this->instructor, $plan->refresh());

        $this->assertSame($plan->id, $lesson->fresh()->learning_plan_id);
    }

    // ── Calculation scenarios ─────────────────────────────────────────

    public function test_lesson_only_plan_calculates_correctly(): void
    {
        $plan = $this->assignedPlan();
        Lesson::factory()->forLearningPlan($plan)->withOutcome(LessonOutcome::Completed)->create();
        Lesson::factory()->forLearningPlan($plan)->withOutcome(LessonOutcome::StudentNoShow)->create();

        $this->assertSame(50, $this->calculate($plan));
    }

    public function test_completed_linked_lesson_contributes(): void
    {
        $plan = $this->assignedPlan();
        Lesson::factory()->forLearningPlan($plan)->withOutcome(LessonOutcome::Completed)->create();

        $this->assertSame(100, $this->calculate($plan));
    }

    public function test_unlinked_lesson_does_not_contribute(): void
    {
        $plan = $this->assignedPlan();
        $this->milestone($plan, LearningPlanMilestoneStatus::Completed);
        Lesson::factory()->withOutcome(LessonOutcome::StudentNoShow)->create();

        $this->assertSame(100, $this->calculate($plan));
    }

    public function test_lesson_linked_to_another_plan_does_not_contribute(): void
    {
        $plan = $this->assignedPlan();
        $otherPlan = $this->assignedPlan($this->newGoal('Other goal'));
        Lesson::factory()->forLearningPlan($otherPlan)->withOutcome(LessonOutcome::Completed)->create();
        Lesson::factory()->forLearningPlan($plan)->withOutcome(LessonOutcome::StudentNoShow)->create();

        $this->assertSame(0, $this->calculate($plan));
        $this->assertSame(100, $this->calculate($otherPlan));
    }

    public function test_pending_unfinalized_lesson_does_not_contribute(): void
    {
        $plan = $this->assignedPlan();
        Lesson::factory()->forLearningPlan($plan)->create();

        $this->assertSame(0, $this->calculate($plan));
    }

    public function test_cancelled_lesson_is_excluded(): void
    {
        $plan = $this->assignedPlan();
        Lesson::factory()->forLearningPlan($plan)->withOutcome(LessonOutcome::Completed)->create();
        Lesson::factory()->forLearningPlan($plan)->withOutcome(LessonOutcome::Cancelled)->create();

        // The cancelled lesson must not dilute the denominator.
        $this->assertSame(100, $this->calculate($plan));
    }

    public function test_each_non_completed_finalized_outcome_follows_the_documented_denominator_rule(): void
    {
        $plan = $this->assignedPlan();
        Lesson::factory()->forLearningPlan($plan)->withOutcome(LessonOutcome::Completed)->create();
        Lesson::factory()->forLearningPlan($plan)->withOutcome(LessonOutcome::StudentNoShow)->create();
        Lesson::factory()->forLearningPlan($plan)->withOutcome(LessonOutcome::InstructorNoShow)->create();
        Lesson::factory()->forLearningPlan($plan)->withOutcome(LessonOutcome::BothAbsent)->create();

        // 1 completed / 4 in the denominator (no-shows count but don't complete).
        $this->assertSame(25, $this->calculate($plan));
    }

    public function test_technical_issue_disputed_lesson_does_not_contribute(): void
    {
        $plan = $this->assignedPlan();
        Lesson::factory()->forLearningPlan($plan)->withOutcome(LessonOutcome::Completed)->create();
        Lesson::factory()->forLearningPlan($plan)->withOutcome(LessonOutcome::TechnicalIssue)->create();

        $this->assertSame(100, $this->calculate($plan));
    }

    public function test_outcome_override_recalculates_correctly(): void
    {
        $plan = $this->assignedPlan();
        $lesson = $this->pastLesson($this->student, $this->instructor, $this->subject);
        app(LessonOutcomeServiceInterface::class)->finalize($lesson, LessonOutcome::Completed);
        $this->assertSame(100, $plan->fresh()->progress_percent);

        app(LessonOutcomeServiceInterface::class)->override($lesson->refresh(), $this->admin(), LessonOutcome::StudentNoShow, 'Evidence was wrong.');

        $this->assertSame(0, $plan->fresh()->progress_percent);
    }

    public function test_soft_deleted_lessons_do_not_contribute(): void
    {
        $plan = $this->assignedPlan();
        $completed = Lesson::factory()->forLearningPlan($plan)->withOutcome(LessonOutcome::Completed)->create();
        $noShow = Lesson::factory()->forLearningPlan($plan)->withOutcome(LessonOutcome::StudentNoShow)->create();
        $noShow->delete();

        $this->assertSame(100, $this->calculate($plan));
        unset($completed);
    }

    public function test_mixed_lesson_milestone_and_homework_evidence_produces_the_documented_composite(): void
    {
        $plan = $this->assignedPlan();
        $this->milestone($plan, LearningPlanMilestoneStatus::Completed); // 100%
        Lesson::factory()->forLearningPlan($plan)->withOutcome(LessonOutcome::StudentNoShow)->create(); // 0%

        // Equal-weight average across the two applicable domains: (100 + 0) / 2 = 50.
        $this->assertSame(50, $this->calculate($plan));
    }

    public function test_empty_review_domain_remains_excluded(): void
    {
        $plan = $this->assignedPlan();
        Lesson::factory()->forLearningPlan($plan)->withOutcome(LessonOutcome::Completed)->create();
        app(LearningPlanService::class)->createReview($this->instructor, $plan->refresh(), ['summary' => 'Great session.']);

        // Reviews stay a documented non-contributor — only the lesson counts.
        $this->assertSame(100, $this->calculate($plan->refresh()));
    }

    public function test_repeated_calculation_is_deterministic_and_non_mutating(): void
    {
        $plan = $this->assignedPlan();
        Lesson::factory()->forLearningPlan($plan)->withOutcome(LessonOutcome::Completed)->create();
        Lesson::factory()->forLearningPlan($plan)->withOutcome(LessonOutcome::StudentNoShow)->create();

        $calculator = app(LearningPlanProgressCalculator::class);
        $first = $calculator->calculate($plan);
        $second = $calculator->calculate($plan->refresh());

        $this->assertSame($first, $second);
        $this->assertSame(0, $plan->lessons()->onlyTrashed()->count());
    }

    // ── Persistence and event scenarios ───────────────────────────────

    public function test_finalization_updates_only_the_linked_writable_plan(): void
    {
        $plan = $this->assignedPlan();
        // A genuinely unrelated plan (different subject, so it is never
        // an ambiguous match candidate for the lesson created below).
        $otherCategory = AcademicCategory::create(['name' => 'Science', 'slug' => 'science-2']);
        $otherSubject = Subject::create(['academic_category_id' => $otherCategory->id, 'name' => 'Physics', 'slug' => 'physics-2']);
        $otherGoal = StudentLearningGoal::create([
            'user_id' => $this->student->id,
            'subject_id' => $otherSubject->id,
            'title' => 'Untouched goal',
            'type' => 'academic',
            'status' => 'active',
        ]);
        $otherPlan = $this->assignedPlan($otherGoal);
        $this->milestone($otherPlan, LearningPlanMilestoneStatus::Pending);

        $lesson = $this->pastLesson($this->student, $this->instructor, $this->subject);
        app(LessonOutcomeServiceInterface::class)->finalize($lesson, LessonOutcome::Completed);

        $this->assertSame(100, $plan->fresh()->progress_percent);
        $this->assertSame(0, $otherPlan->fresh()->progress_percent);
    }

    public function test_repeated_event_delivery_is_idempotent(): void
    {
        $plan = $this->assignedPlan();
        $lesson = $this->pastLesson($this->student, $this->instructor, $this->subject);

        app(LessonOutcomeServiceInterface::class)->finalize($lesson, LessonOutcome::Completed);
        $touchedAt = $plan->fresh()->updated_at;

        // A second finalize of the identical outcome is a documented
        // idempotent no-op at the lesson level and must not move the
        // plan's timestamp either.
        app(LessonOutcomeServiceInterface::class)->finalize($lesson->refresh(), LessonOutcome::Completed);

        $this->assertSame(100, $plan->fresh()->progress_percent);
        $this->assertTrue($plan->fresh()->updated_at->equalTo($touchedAt));
    }

    public function test_missing_or_deleted_plan_is_handled_safely_by_the_listener(): void
    {
        $plan = $this->assignedPlan();
        $lesson = $this->pastLesson($this->student, $this->instructor, $this->subject);
        $plan->delete();

        // No exception even though the linked plan is now soft-deleted.
        app(LessonOutcomeServiceInterface::class)->finalize($lesson, LessonOutcome::Completed);

        $this->assertTrue($plan->fresh()->trashed());
    }

    public function test_completed_or_archived_plan_progress_remains_unchanged_after_finalization(): void
    {
        $plan = $this->assignedPlan();
        $lesson = $this->pastLesson($this->student, $this->instructor, $this->subject);

        $milestone = app(LearningPlanService::class)->createMilestone($this->instructor, $plan, ['title' => 'Only']);
        app(LearningPlanService::class)->completeMilestone($this->instructor, $milestone);
        $completed = app(LearningPlanService::class)->completePlan($this->instructor, $plan->refresh());
        $this->assertSame(100, $completed->progress_percent);

        // The lesson resolved to this plan before it completed; a
        // no-show finalized afterward must never drag a frozen,
        // Completed plan's percentage back down.
        app(LessonOutcomeServiceInterface::class)->finalize($lesson, LessonOutcome::StudentNoShow);

        $this->assertSame(100, $completed->fresh()->progress_percent);
        $this->assertSame(LearningPlanStatus::Completed, $completed->fresh()->status);
    }

    public function test_bounded_query_behaviour_is_preserved(): void
    {
        $plan = $this->assignedPlan();
        for ($i = 0; $i < 5; $i++) {
            Lesson::factory()->forLearningPlan($plan)->withOutcome(LessonOutcome::Completed)->create();
        }
        $plan->refresh();

        DB::enableQueryLog();
        app(LearningPlanProgressCalculator::class)->calculate($plan);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(6, $count);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function calculate(StudentLearningPlan $plan): int
    {
        return app(LearningPlanProgressCalculator::class)->calculate($plan);
    }

    private function assignedPlan(?StudentLearningGoal $goal = null, bool $withOtherInstructor = false): StudentLearningPlan
    {
        $service = app(LearningPlanService::class);
        $plan = $service->createDraftFromGoal($this->student, $goal ?? $this->goal);

        Permission::firstOrCreate(['name' => 'Update:StudentLearningPlan', 'guard_name' => 'web']);
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $manager->assignRole('manager');
        $manager->givePermissionTo('Update:StudentLearningPlan');

        $instructor = $withOtherInstructor ? $this->makeInstructor() : $this->instructor;

        return $service->assignInstructor($manager, $plan, $instructor);
    }

    private function newGoal(string $title): StudentLearningGoal
    {
        return StudentLearningGoal::create([
            'user_id' => $this->student->id,
            'subject_id' => $this->subject->id,
            'academic_level_id' => $this->level->id,
            'title' => $title,
            'type' => 'academic',
            'status' => 'active',
        ]);
    }

    private function milestone(StudentLearningPlan $plan, LearningPlanMilestoneStatus $status): void
    {
        $plan->milestones()->create([
            'title' => 'Milestone '.$status->value.' '.uniqid(),
            'status' => $status,
            'completed_at' => $status === LearningPlanMilestoneStatus::Completed ? now() : null,
            'created_by' => $this->instructor->id,
            'updated_by' => $this->instructor->id,
        ]);
    }

    private function createLesson(User $student, User $instructor, Subject $subject): Lesson
    {
        $booking = Booking::factory()->confirmed()->create([
            'booking_type_id' => BookingType::factory()->paid(),
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'meta' => ['subject' => $subject->slug],
        ]);

        return app(LessonLifecycleServiceInterface::class)->createFromBooking($booking);
    }

    private function pastLesson(User $student, User $instructor, Subject $subject): Lesson
    {
        $endsAt = now()->subHours(1)->startOfHour();

        $booking = Booking::factory()->confirmed()->create([
            'booking_type_id' => BookingType::factory()->paid(),
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'meta' => ['subject' => $subject->slug],
            'starts_at' => $endsAt->copy()->subMinutes(60),
            'ends_at' => $endsAt,
        ]);

        return app(LessonLifecycleServiceInterface::class)->createFromBooking($booking);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('manager');

        return $admin;
    }

    private function makeInstructor(): User
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');
        $instructor->profile->update([
            'profile_visibility' => 'public',
            'instructor_status' => InstructorStatus::Approved,
        ]);

        return $instructor;
    }
}
