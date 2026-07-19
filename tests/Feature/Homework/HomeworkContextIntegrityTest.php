<?php

declare(strict_types=1);

namespace Tests\Feature\Homework;

use App\Booking\Enums\BookingStatus;
use App\Enums\InstructorStatus;
use App\Enums\LearningGoalStatus;
use App\Enums\LearningPlanStatus;
use App\Enums\StudentStatus;
use App\Exceptions\Student\StudentActionNotAvailableException;
use App\Homework\Contracts\HomeworkServiceInterface;
use App\Homework\Enums\HomeworkStatus;
use App\Homework\Exceptions\InvalidHomeworkContextException;
use App\Models\AcademicCategory;
use App\Models\Booking;
use App\Models\HomeworkAssignment;
use App\Models\StudentLearningGoal;
use App\Models\StudentLearningPlan;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 24J — GAP-021 (SRS-7-1 / SRS-7-12, SRS §7.19): every homework
 * assignment must reference a Lesson (completed booking) or a Learning
 * Plan; both links are allowed and must be cross-consistent. Enforced by
 * HomeworkContextValidator inside HomeworkService::assign()/changeContext()
 * and by the chk_homework_assignments_context CHECK constraint.
 */
final class HomeworkContextIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;

    private User $student;

    private HomeworkServiceInterface $homework;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        $this->instructor = $this->instructor();
        $this->student = $this->activeStudent();
        $this->homework = app(HomeworkServiceInterface::class);
    }

    // ── Create: valid contexts ───────────────────────────────────────

    public function test_create_with_valid_completed_booking_only_succeeds(): void
    {
        $booking = $this->completedBooking();

        $assignment = $this->homework->assign(
            $this->instructor,
            $this->student,
            $this->attributes(),
            bookingId: $booking->id,
        );

        $this->assertSame($booking->id, $assignment->booking_id);
        $this->assertNull($assignment->learning_plan_id);
        $this->assertSame(HomeworkStatus::Pending, $assignment->status);
    }

    public function test_create_with_valid_learning_plan_only_succeeds(): void
    {
        $plan = $this->plan();

        $assignment = $this->homework->assign(
            $this->instructor,
            $this->student,
            $this->attributes(),
            learningPlanId: $plan->id,
        );

        $this->assertNull($assignment->booking_id);
        $this->assertSame($plan->id, $assignment->learning_plan_id);
    }

    public function test_create_with_both_consistent_contexts_succeeds(): void
    {
        $booking = $this->completedBooking();
        $plan = $this->plan();

        $assignment = $this->homework->assign(
            $this->instructor,
            $this->student,
            $this->attributes(),
            bookingId: $booking->id,
            learningPlanId: $plan->id,
        );

        $this->assertSame($booking->id, $assignment->booking_id);
        $this->assertSame($plan->id, $assignment->learning_plan_id);
    }

    // ── Create: rejected contexts ────────────────────────────────────

    public function test_create_with_neither_context_fails_at_service_level_with_no_row_and_no_audit(): void
    {
        try {
            $this->homework->assign($this->instructor, $this->student, $this->attributes());
            $this->fail('Expected InvalidHomeworkContextException.');
        } catch (InvalidHomeworkContextException $e) {
            $this->assertStringContainsString('lesson or a learning plan', $e->getMessage());
        }

        $this->assertSame(0, HomeworkAssignment::query()->count());
        $this->assertSame(0, Activity::query()->where('log_name', 'homework')->count());
    }

    public function test_database_rejects_a_raw_insert_with_neither_context(): void
    {
        $this->expectException(QueryException::class);

        DB::table('homework_assignments')->insert([
            'id' => (string) Str::uuid(),
            'booking_id' => null,
            'learning_plan_id' => null,
            'teacher_id' => $this->instructor->id,
            'student_id' => $this->student->id,
            'subject' => 'maths',
            'title' => 'Raw insert',
            'due_at' => now()->addWeek(),
            'status' => HomeworkStatus::Pending->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_wrong_student_booking_is_rejected(): void
    {
        $otherStudent = $this->activeStudent();
        $booking = $this->completedBooking(student: $otherStudent);

        $this->expectException(InvalidHomeworkContextException::class);
        $this->expectExceptionMessage('does not belong to this student');

        $this->homework->assign($this->instructor, $this->student, $this->attributes(), bookingId: $booking->id);
    }

    public function test_wrong_instructor_booking_is_rejected(): void
    {
        $otherInstructor = $this->instructor();
        $booking = $this->completedBooking(instructor: $otherInstructor);

        $this->expectException(InvalidHomeworkContextException::class);
        $this->expectExceptionMessage('your own lessons');

        $this->homework->assign($this->instructor, $this->student, $this->attributes(), bookingId: $booking->id);
    }

    public function test_wrong_student_plan_is_rejected(): void
    {
        $otherStudent = $this->activeStudent();
        $plan = $this->plan(student: $otherStudent);

        $this->expectException(InvalidHomeworkContextException::class);
        $this->expectExceptionMessage('does not belong to this student');

        $this->homework->assign($this->instructor, $this->student, $this->attributes(), learningPlanId: $plan->id);
    }

    public function test_wrong_instructor_plan_is_rejected(): void
    {
        $plan = $this->plan(instructor: $this->instructor());

        $this->expectException(InvalidHomeworkContextException::class);
        $this->expectExceptionMessage('plans you lead');

        $this->homework->assign($this->instructor, $this->student, $this->attributes(), learningPlanId: $plan->id);
    }

    public function test_plan_without_primary_instructor_is_rejected(): void
    {
        $plan = $this->plan(instructor: false);

        $this->expectException(InvalidHomeworkContextException::class);
        $this->expectExceptionMessage('plans you lead');

        $this->homework->assign($this->instructor, $this->student, $this->attributes(), learningPlanId: $plan->id);
    }

    public function test_contradictory_booking_and_plan_are_rejected(): void
    {
        // The booking matches the assignment's student; the plan belongs
        // to a different student — the combined context is contradictory.
        $booking = $this->completedBooking();
        $plan = $this->plan(student: $this->activeStudent());

        $this->expectException(InvalidHomeworkContextException::class);

        $this->homework->assign(
            $this->instructor,
            $this->student,
            $this->attributes(),
            bookingId: $booking->id,
            learningPlanId: $plan->id,
        );
    }

    // ── Lifecycle-state eligibility ──────────────────────────────────

    public function test_non_completed_booking_states_are_rejected_for_new_homework(): void
    {
        // SRS §11.32 / §6.13: instructors assign homework AFTER lesson
        // completion — Pending, Confirmed, Cancelled and NoShow lessons
        // cannot receive new homework.
        foreach ([BookingStatus::Pending, BookingStatus::Confirmed, BookingStatus::Cancelled, BookingStatus::NoShow] as $status) {
            $booking = $this->completedBooking();
            $booking->forceFill(['status' => $status])->save();

            try {
                $this->homework->assign($this->instructor, $this->student, $this->attributes(), bookingId: $booking->id);
                $this->fail("Expected rejection for booking status {$status->value}.");
            } catch (InvalidHomeworkContextException $e) {
                $this->assertStringContainsString('completed lesson', $e->getMessage());
            }
        }

        $this->assertSame(0, HomeworkAssignment::query()->count());
    }

    public function test_completed_and_archived_plans_are_rejected_for_new_homework(): void
    {
        foreach ([LearningPlanStatus::Completed, LearningPlanStatus::Archived] as $status) {
            $plan = $this->plan(status: $status);

            try {
                $this->homework->assign($this->instructor, $this->student, $this->attributes(), learningPlanId: $plan->id);
                $this->fail("Expected rejection for plan status {$status->value}.");
            } catch (InvalidHomeworkContextException $e) {
                $this->assertStringContainsString('cannot receive new homework', $e->getMessage());
            }
        }
    }

    public function test_soft_deleted_booking_and_plan_are_rejected_for_new_homework(): void
    {
        $booking = $this->completedBooking();
        $booking->delete();

        try {
            $this->homework->assign($this->instructor, $this->student, $this->attributes(), bookingId: $booking->id);
            $this->fail('Expected rejection for soft-deleted booking.');
        } catch (InvalidHomeworkContextException $e) {
            $this->assertStringContainsString('no longer available', $e->getMessage());
        }

        $plan = $this->plan();
        $plan->delete();

        $this->expectException(InvalidHomeworkContextException::class);
        $this->expectExceptionMessage('no longer available');

        $this->homework->assign($this->instructor, $this->student, $this->attributes(), learningPlanId: $plan->id);
    }

    // ── Update / context changes ─────────────────────────────────────

    public function test_update_cannot_clear_the_final_remaining_context(): void
    {
        $assignment = $this->assignment(bookingId: $this->completedBooking()->id);

        $this->expectException(InvalidHomeworkContextException::class);
        $this->expectExceptionMessage('lesson or a learning plan');

        $this->homework->changeContext($assignment, $this->instructor, ['booking_id' => null]);
    }

    public function test_update_may_remove_one_link_when_the_other_remains(): void
    {
        $booking = $this->completedBooking();
        $plan = $this->plan();
        $assignment = $this->assignment(bookingId: $booking->id, learningPlanId: $plan->id);

        $updated = $this->homework->changeContext($assignment, $this->instructor, ['learning_plan_id' => null]);

        $this->assertSame($booking->id, $updated->booking_id);
        $this->assertNull($updated->learning_plan_id);
    }

    public function test_update_by_another_instructor_is_rejected(): void
    {
        $assignment = $this->assignment(bookingId: $this->completedBooking()->id);

        $this->expectException(AuthorizationException::class);

        $this->homework->changeContext($assignment, $this->instructor(), ['learning_plan_id' => $this->plan()->id]);
    }

    public function test_concurrent_dual_edit_cannot_leave_both_links_null(): void
    {
        $booking = $this->completedBooking();
        $plan = $this->plan();
        $assignment = $this->assignment(bookingId: $booking->id, learningPlanId: $plan->id);

        // Two editors load the both-linked assignment; the first clears
        // the plan link. The second — still holding the stale both-linked
        // model — tries to clear the booking link. The service re-reads
        // the row inside its transaction, merges over the FRESH state
        // (plan already null), and must reject the neither-link result.
        $staleCopy = HomeworkAssignment::query()->findOrFail($assignment->id);

        $this->homework->changeContext($assignment, $this->instructor, ['learning_plan_id' => null]);

        try {
            $this->homework->changeContext($staleCopy, $this->instructor, ['booking_id' => null]);
            $this->fail('Expected the stale second edit to be rejected.');
        } catch (InvalidHomeworkContextException $e) {
            $this->assertStringContainsString('lesson or a learning plan', $e->getMessage());
        }

        $fresh = $assignment->fresh();
        $this->assertSame($booking->id, $fresh->booking_id);
        $this->assertNull($fresh->learning_plan_id);
    }

    public function test_plan_archived_between_selection_and_submission_is_rejected(): void
    {
        $plan = $this->plan();

        // The plan was writable when the UI rendered its options; it is
        // archived before the instructor submits. The fresh in-transaction
        // read catches it.
        $plan->forceFill(['status' => LearningPlanStatus::Archived, 'archived_at' => now()])->save();

        $this->expectException(InvalidHomeworkContextException::class);
        $this->expectExceptionMessage('cannot receive new homework');

        $this->homework->assign($this->instructor, $this->student, $this->attributes(), learningPlanId: $plan->id);
    }

    public function test_duplicate_create_submissions_produce_independent_valid_rows(): void
    {
        // No SRS uniqueness rule exists for homework per lesson; a double
        // submission yields two rows, each satisfying the invariant.
        $booking = $this->completedBooking();

        $this->homework->assign($this->instructor, $this->student, $this->attributes(), bookingId: $booking->id);
        $this->homework->assign($this->instructor, $this->student, $this->attributes(), bookingId: $booking->id);

        $this->assertSame(2, HomeworkAssignment::query()->whereNotNull('booking_id')->count());
    }

    // ── Actor authorization ──────────────────────────────────────────

    public function test_student_cannot_create_assignments_via_direct_service_call(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->homework->assign($this->student, $this->student, $this->attributes(), bookingId: $this->completedBooking()->id);
    }

    public function test_admin_without_instructor_role_cannot_assign_homework(): void
    {
        // There has never been an admin homework-creation path; the
        // explicit actor check keeps direct service calls closed too.
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $admin->assignRole('super_admin');

        $this->expectException(AuthorizationException::class);

        $this->homework->assign($admin, $this->student, $this->attributes(), bookingId: $this->completedBooking()->id);
    }

    // ── Existing assignments, preservation, unchanged flows ──────────

    public function test_existing_booking_only_assignment_remains_usable_end_to_end(): void
    {
        $assignment = $this->assignment(bookingId: $this->completedBooking()->id);

        $this->homework->submit($assignment, 'My answer.');
        $graded = $this->homework->review($assignment->fresh(), 'Well done.', 'A');

        $this->assertSame(HomeworkStatus::Graded, $graded->status);
    }

    public function test_plan_only_assignment_supports_submission_and_review_unchanged(): void
    {
        $assignment = $this->assignment(learningPlanId: $this->plan()->id);

        $this->homework->submit($assignment, 'Plan-level work.');
        $graded = $this->homework->review($assignment->fresh(), 'Good progress.', null);

        $this->assertSame(HomeworkStatus::Graded, $graded->status);
    }

    public function test_plan_archival_preserves_the_historical_assignment_link(): void
    {
        $plan = $this->plan();
        $assignment = $this->assignment(learningPlanId: $plan->id);

        $plan->forceFill(['status' => LearningPlanStatus::Archived, 'archived_at' => now()])->save();
        $plan->delete();

        $fresh = $assignment->fresh();
        $this->assertSame($plan->id, $fresh->learning_plan_id);
        // withTrashed relation keeps historical context resolvable.
        $this->assertNotNull($fresh->learningPlan);
        $this->assertSame(LearningPlanStatus::Archived, $fresh->learningPlan->status);
    }

    public function test_plan_hard_delete_with_dependent_homework_is_refused_by_the_database(): void
    {
        $plan = $this->plan();
        $this->assignment(learningPlanId: $plan->id);

        $this->expectException(QueryException::class);

        $plan->forceDelete();
    }

    public function test_suspended_student_submission_remains_blocked_by_phase_24h2_guard(): void
    {
        $assignment = $this->assignment(bookingId: $this->completedBooking()->id);

        $this->student->profile()->update(['student_status' => StudentStatus::Suspended]);

        $this->expectException(StudentActionNotAvailableException::class);

        $this->homework->submit($assignment, 'Should be blocked.');
    }

    public function test_dashboard_stats_count_plan_only_and_booking_only_assignments_alike(): void
    {
        $this->assignment(bookingId: $this->completedBooking()->id);
        $this->assignment(learningPlanId: $this->plan()->id);

        $stats = $this->homework->statsForStudent($this->student->id);

        $this->assertSame(2, (int) $stats->pending);
    }

    // ── Audit ────────────────────────────────────────────────────────

    public function test_successful_assign_writes_audit_with_safe_context_metadata_only(): void
    {
        $booking = $this->completedBooking();
        $plan = $this->plan();

        $this->homework->assign(
            $this->instructor,
            $this->student,
            $this->attributes() + ['description' => 'Private pedagogical notes'],
            bookingId: $booking->id,
            learningPlanId: $plan->id,
        );

        $activity = Activity::query()
            ->where('log_name', 'homework')
            ->where('event', 'homework_assigned')
            ->sole();

        $this->assertSame('Homework assigned.', $activity->description);
        $this->assertSame('lesson_and_plan', $activity->properties['linked_to']);
        $this->assertSame($booking->reference, $activity->properties['booking_reference']);
        $this->assertSame($plan->id, $activity->properties['learning_plan_id']);
        // No homework content, no student personal details in properties.
        $this->assertStringNotContainsString('Private pedagogical notes', $activity->properties->toJson());
        $this->assertStringNotContainsString($this->student->email, $activity->properties->toJson());
    }

    public function test_context_change_writes_audit_and_failed_validation_writes_none(): void
    {
        $booking = $this->completedBooking();
        $assignment = $this->assignment(bookingId: $booking->id, learningPlanId: $this->plan()->id);

        $this->homework->changeContext($assignment, $this->instructor, ['learning_plan_id' => null]);

        $this->assertSame(1, Activity::query()->where('log_name', 'homework')->where('event', 'homework_context_updated')->count());

        $before = Activity::query()->where('log_name', 'homework')->count();

        try {
            $this->homework->changeContext($assignment->fresh(), $this->instructor, ['booking_id' => null]);
        } catch (InvalidHomeworkContextException) {
            // expected — final remaining link
        }

        $this->assertSame($before, Activity::query()->where('log_name', 'homework')->count());
    }

    public function test_no_external_provider_call_occurs_during_assignment(): void
    {
        Http::fake();

        $this->homework->assign($this->instructor, $this->student, $this->attributes(), bookingId: $this->completedBooking()->id);

        Http::assertNothingSent();
    }

    // ── Fixtures ─────────────────────────────────────────────────────

    private function instructor(): User
    {
        $instructor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $instructor->assignRole('instructor');
        $instructor->profile()->update(['instructor_status' => InstructorStatus::Active]);

        return $instructor;
    }

    private function activeStudent(): User
    {
        $student = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $student->assignRole('student');
        $student->profile()->update(['student_status' => StudentStatus::Active]);

        return $student;
    }

    private function completedBooking(?User $instructor = null, ?User $student = null): Booking
    {
        return Booking::factory()->completed()->create([
            'instructor_id' => ($instructor ?? $this->instructor)->id,
            'student_id' => ($student ?? $this->student)->id,
        ]);
    }

    /**
     * @param  User|false|null  $instructor  false = plan without a primary instructor
     */
    private function plan(
        ?User $student = null,
        User|false|null $instructor = null,
        LearningPlanStatus $status = LearningPlanStatus::Active,
    ): StudentLearningPlan {
        $student ??= $this->student;

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
            'primary_instructor_user_id' => $instructor === false ? null : ($instructor ?? $this->instructor)->id,
            'subject_id' => $subject->id,
            'title' => 'Algebra plan',
            'status' => $status,
            'progress_percent' => 0,
        ]);
    }

    private function assignment(?string $bookingId = null, ?int $learningPlanId = null): HomeworkAssignment
    {
        return $this->homework->assign(
            $this->instructor,
            $this->student,
            $this->attributes(),
            bookingId: $bookingId,
            learningPlanId: $learningPlanId,
        );
    }

    /** @return array<string, mixed> */
    private function attributes(): array
    {
        return [
            'title' => 'Fractions worksheet',
            'subject' => 'maths',
            'due_at' => now()->addWeek(),
        ];
    }
}
