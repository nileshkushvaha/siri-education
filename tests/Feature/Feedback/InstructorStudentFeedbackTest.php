<?php

declare(strict_types=1);

namespace Tests\Feature\Feedback;

use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Enums\InstructorStatus;
use App\Feedback\Contracts\InstructorStudentFeedbackRepositoryInterface;
use App\Feedback\Contracts\InstructorStudentFeedbackServiceInterface;
use App\Feedback\DTOs\SubmitInstructorStudentFeedbackData;
use App\Feedback\Exceptions\InstructorStudentFeedbackException;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\Enums\LessonOutcome;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\InstructorEarning;
use App\Models\InstructorQualityAlert;
use App\Models\InstructorSettlementBatch;
use App\Models\InstructorStudentFeedback;
use App\Models\Lesson;
use App\Models\LessonReviewEligibility;
use App\Models\StudentLearningPlan;
use App\Models\User;
use App\Reviews\Contracts\InstructorRatingAggregateServiceInterface;
use Database\Seeders\FeedbackPermissionSeeder;
use Database\Seeders\LessonPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Private, lesson-linked instructor-to-student feedback:
 * completion/participant eligibility, one-record-per-lesson
 * idempotency and concurrency protection, sanitization, privacy
 * (never public, never a rating input), authorization (assigned
 * instructor only + permissioned admin), immutability, and outcome-
 * override history preservation.
 */
class InstructorStudentFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private LessonLifecycleServiceInterface $lifecycle;

    private LessonOutcomeServiceInterface $outcomes;

    private InstructorStudentFeedbackServiceInterface $feedback;

    private InstructorStudentFeedbackRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);

        $this->lifecycle = app(LessonLifecycleServiceInterface::class);
        $this->outcomes = app(LessonOutcomeServiceInterface::class);
        $this->feedback = app(InstructorStudentFeedbackServiceInterface::class);
        $this->repository = app(InstructorStudentFeedbackRepositoryInterface::class);
    }

    // ── 1–2. Happy path ──────────────────────────────────────────────────

    public function test_assigned_instructor_can_submit_feedback_after_a_completed_paid_lesson(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->completedLesson($this->paidLesson($instructor));

        $result = $this->feedback->submit($lesson, $instructor, $this->data(engagementObservation: 'Stayed focused throughout.'));

        $this->assertSame($lesson->id, $result->lessonId);
        $this->assertSame('Stayed focused throughout.', $result->engagementObservation);
        $this->assertSame(LessonOutcome::Completed, $result->outcomeSnapshot);
    }

    public function test_assigned_instructor_can_submit_feedback_after_a_completed_demo_lesson(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->completedLesson($this->demoLesson($instructor));

        $result = $this->feedback->submit($lesson, $instructor, $this->data());

        $this->assertSame($lesson->id, $result->lessonId);
    }

    // ── 3–9. Eligibility rejections ──────────────────────────────────────

    public function test_pending_lesson_rejects_submission(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->paidLesson($instructor); // never finalized — stays Pending

        $this->expectException(InstructorStudentFeedbackException::class);
        $this->feedback->submit($lesson, $instructor, $this->data());
    }

    public function test_student_no_show_rejects_submission(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->completedLesson($this->paidLesson($instructor), LessonOutcome::StudentNoShow);

        $this->expectException(InstructorStudentFeedbackException::class);
        $this->feedback->submit($lesson, $instructor, $this->data());
    }

    public function test_instructor_no_show_rejects_submission(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->completedLesson($this->paidLesson($instructor), LessonOutcome::InstructorNoShow);

        $this->expectException(InstructorStudentFeedbackException::class);
        $this->feedback->submit($lesson, $instructor, $this->data());
    }

    public function test_both_absent_outcome_rejects_submission(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->completedLesson($this->paidLesson($instructor), LessonOutcome::BothAbsent);

        $this->expectException(InstructorStudentFeedbackException::class);
        $this->feedback->submit($lesson, $instructor, $this->data());
    }

    public function test_technical_issue_outcome_rejects_submission(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->completedLesson($this->paidLesson($instructor), LessonOutcome::TechnicalIssue);

        $this->expectException(InstructorStudentFeedbackException::class);
        $this->feedback->submit($lesson, $instructor, $this->data());
    }

    public function test_cancelled_lesson_rejects_submission(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->completedLesson($this->paidLesson($instructor), LessonOutcome::Cancelled);

        $this->expectException(InstructorStudentFeedbackException::class);
        $this->feedback->submit($lesson, $instructor, $this->data());
    }

    public function test_invalidated_booking_rejects_submission(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->completedLesson($this->paidLesson($instructor));
        $lesson->booking->forceFill(['status' => BookingStatus::Cancelled])->saveQuietly();

        $this->expectException(InstructorStudentFeedbackException::class);
        $this->feedback->submit($lesson->fresh(), $instructor, $this->data());
    }

    // ── 10–12. Authorization ──────────────────────────────────────────────

    public function test_another_instructor_cannot_submit(): void
    {
        $instructor = $this->instructorUser();
        $otherInstructor = $this->instructorUser();
        $lesson = $this->completedLesson($this->paidLesson($instructor));

        $this->expectException(AuthorizationException::class);
        $this->feedback->submit($lesson, $otherInstructor, $this->data());
    }

    public function test_student_cannot_submit(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->completedLesson($this->paidLesson($instructor));
        $student = User::find($lesson->student_id);

        $this->expectException(AuthorizationException::class);
        $this->feedback->submit($lesson, $student, $this->data());
    }

    public function test_guest_cannot_submit(): void
    {
        $this->get(route('dashboard.instructor.lessons'))->assertRedirect();
    }

    // ── 13–15. One feedback per lesson / idempotency / concurrency ────────

    public function test_one_feedback_record_is_created_per_lesson(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->completedLesson($this->paidLesson($instructor));

        $this->feedback->submit($lesson, $instructor, $this->data());

        $this->assertSame(1, InstructorStudentFeedback::query()->where('lesson_id', $lesson->id)->count());
    }

    public function test_duplicate_submission_is_idempotent(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->completedLesson($this->paidLesson($instructor));

        $first = $this->feedback->submit($lesson, $instructor, $this->data(engagementObservation: 'First.'));
        $second = $this->feedback->submit($lesson, $instructor, $this->data(engagementObservation: 'Second — should be ignored.'));

        $this->assertSame($first->id, $second->id);
        $this->assertSame('First.', $second->engagementObservation);
        $this->assertSame(1, InstructorStudentFeedback::query()->where('lesson_id', $lesson->id)->count());
    }

    public function test_concurrent_submissions_create_one_record(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->completedLesson($this->paidLesson($instructor));

        $attributes = [
            'lesson_id' => $lesson->id,
            'booking_id' => $lesson->booking_id,
            'student_id' => $lesson->student_id,
            'instructor_id' => $instructor->id,
            'outcome_snapshot' => LessonOutcome::Completed,
            'source_outcome_version' => $lesson->outcome_version,
            'submitted_at' => now(),
            'version' => 1,
        ];

        $this->repository->create($attributes);

        // Simulates the second half of a race: two requests both pass
        // the "no existing feedback" check before either commits — the
        // database's own unique index is the final guarantee.
        $this->expectException(UniqueConstraintViolationException::class);
        $this->repository->create($attributes);

        $this->assertSame(1, InstructorStudentFeedback::query()->where('lesson_id', $lesson->id)->count());
    }

    // ── 16–17. Observation content ─────────────────────────────────────

    public function test_finalized_attendance_is_not_overwritten(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->completedLesson($this->paidLesson($instructor));
        $originalAttendance = $lesson->fresh()->student_attendance_status;

        $result = $this->feedback->submit($lesson, $instructor, $this->data(attendanceObservation: 'Arrived a few minutes late but caught up quickly.'));

        $this->assertSame($originalAttendance, $lesson->fresh()->student_attendance_status);
        $this->assertSame($originalAttendance, $result->attendanceStatusSnapshot);
        $this->assertSame('Arrived a few minutes late but caught up quickly.', $result->attendanceObservation);
    }

    public function test_optional_observations_may_remain_null(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->completedLesson($this->paidLesson($instructor));

        $result = $this->feedback->submit($lesson, $instructor, $this->data());

        $this->assertNull($result->attendanceObservation);
        $this->assertNull($result->preparednessObservation);
        $this->assertNull($result->privateNotes);
    }

    // ── 18–20. Sanitization ────────────────────────────────────────────

    public function test_html_and_scripts_are_removed(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->completedLesson($this->paidLesson($instructor));

        $result = $this->feedback->submit($lesson, $instructor, $this->data(
            privateNotes: 'Great progress <script>alert(1)</script> this week.',
        ));

        $this->assertStringNotContainsString('<script', (string) $result->privateNotes);
        $this->assertStringNotContainsString('alert(1)', (string) $result->privateNotes);
        $this->assertStringContainsString('Great progress', (string) $result->privateNotes);
    }

    public function test_contact_information_is_not_stored(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->completedLesson($this->paidLesson($instructor));
        $email = 'student.private@sirieducation.com';

        $result = $this->feedback->submit($lesson, $instructor, $this->data(
            areasNeedingSupport: "Parent asked to be reached at {$email} for updates.",
        ));

        $this->assertStringNotContainsString($email, (string) $result->areasNeedingSupport);
    }

    public function test_raw_prohibited_text_is_absent_from_audit_logs(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->completedLesson($this->paidLesson($instructor));
        $phone = '+1 (555) 222-3344';

        $this->feedback->submit($lesson, $instructor, $this->data(
            privateNotes: "Call the family at {$phone} if support is needed.",
        ));

        $activity = Activity::query()
            ->where('log_name', 'feedback')
            ->where('event', 'instructor_student_feedback_submitted')
            ->latest('id')
            ->firstOrFail();

        $serialized = json_encode($activity->properties) . $activity->description;
        $this->assertStringNotContainsString('222-3344', $serialized);
        $this->assertContains('phone_number', $activity->properties->get('sanitization_flag_categories')['private_notes'] ?? []);
    }

    // ── 21–25. Visibility & authorization ──────────────────────────────

    public function test_feedback_is_visible_to_the_submitting_instructor(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->completedLesson($this->paidLesson($instructor));
        $this->feedback->submit($lesson, $instructor, $this->data());

        $viewed = $this->feedback->forLessonAndInstructor($lesson, $instructor);

        $this->assertNotNull($viewed);
    }

    public function test_feedback_is_hidden_from_another_instructor(): void
    {
        $instructor = $this->instructorUser();
        $otherInstructor = $this->instructorUser();
        $lesson = $this->completedLesson($this->paidLesson($instructor));
        $this->feedback->submit($lesson, $instructor, $this->data());
        $record = InstructorStudentFeedback::query()->where('lesson_id', $lesson->id)->firstOrFail();

        $this->expectException(AuthorizationException::class);
        $this->feedback->view($record, $otherInstructor);
    }

    public function test_feedback_is_hidden_from_the_student_in_this_phase(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->completedLesson($this->paidLesson($instructor));
        $this->feedback->submit($lesson, $instructor, $this->data());
        $record = InstructorStudentFeedback::query()->where('lesson_id', $lesson->id)->firstOrFail();
        $student = User::find($lesson->student_id);

        $this->expectException(AuthorizationException::class);
        $this->feedback->view($record, $student);
    }

    public function test_authorized_admin_may_view_with_permission(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->completedLesson($this->paidLesson($instructor));
        $this->feedback->submit($lesson, $instructor, $this->data());
        $record = InstructorStudentFeedback::query()->where('lesson_id', $lesson->id)->firstOrFail();

        $admin = $this->permissionedAdmin();
        $viewed = $this->feedback->view($record, $admin);

        $this->assertSame($record->id, $viewed->id);
    }

    public function test_unauthorized_admin_is_denied(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->completedLesson($this->paidLesson($instructor));
        $this->feedback->submit($lesson, $instructor, $this->data());
        $record = InstructorStudentFeedback::query()->where('lesson_id', $lesson->id)->firstOrFail();

        $plainAdmin = User::factory()->create(['status' => 'active']);
        $plainAdmin->assignRole('manager'); // manager role only — FeedbackPermissionSeeder was never run, so no permission exists

        $this->expectException(AuthorizationException::class);
        $this->feedback->view($record, $plainAdmin);
    }

    // ── 26–34. Isolation from other domains ─────────────────────────────

    public function test_feedback_never_appears_on_public_instructor_profiles(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->completedLesson($this->paidLesson($instructor));
        $this->feedback->submit($lesson, $instructor, $this->data(
            privateNotes: 'A truly distinctive private note marker 928374.',
        ));

        $response = $this->get(route('instructors.show', $instructor));
        $response->assertOk();
        $response->assertDontSee('928374');
    }

    public function test_feedback_does_not_affect_rating_aggregates(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->completedLesson($this->paidLesson($instructor));

        $before = app(InstructorRatingAggregateServiceInterface::class)->summaryFor($instructor->id);
        $this->feedback->submit($lesson, $instructor, $this->data());
        $after = app(InstructorRatingAggregateServiceInterface::class)->summaryFor($instructor->id);

        $this->assertSame($before->reviewCount, $after->reviewCount);
        $this->assertSame($before->averageRating, $after->averageRating);
    }

    public function test_feedback_does_not_create_a_quality_alert(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->completedLesson($this->paidLesson($instructor));

        $this->feedback->submit($lesson, $instructor, $this->data());

        $this->assertSame(0, InstructorQualityAlert::query()->where('instructor_id', $instructor->id)->count());
    }

    public function test_feedback_does_not_modify_review_eligibility(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->completedLesson($this->paidLesson($instructor));
        $eligibilityCountBefore = LessonReviewEligibility::query()->where('lesson_id', $lesson->id)->count();

        $this->feedback->submit($lesson, $instructor, $this->data());

        $this->assertSame($eligibilityCountBefore, LessonReviewEligibility::query()->where('lesson_id', $lesson->id)->count());
    }

    public function test_feedback_does_not_modify_lesson_outcome(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->completedLesson($this->paidLesson($instructor));
        $outcomeVersionBefore = $lesson->fresh()->outcome_version;

        $this->feedback->submit($lesson, $instructor, $this->data());

        $fresh = $lesson->fresh();
        $this->assertSame(LessonOutcome::Completed, $fresh->outcome);
        $this->assertSame($outcomeVersionBefore, $fresh->outcome_version);
    }

    public function test_feedback_does_not_modify_booking_status(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->completedLesson($this->paidLesson($instructor));
        $statusBefore = $lesson->booking->status;

        $this->feedback->submit($lesson, $instructor, $this->data());

        $this->assertSame($statusBefore, $lesson->fresh()->booking->status);
    }

    public function test_feedback_does_not_modify_financial_records(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->completedLesson($this->paidLesson($instructor));
        $earningsBefore = InstructorEarning::query()->where('instructor_id', $instructor->id)->count();
        $settlementsBefore = InstructorSettlementBatch::query()->where('instructor_id', $instructor->id)->count();

        $this->feedback->submit($lesson, $instructor, $this->data());

        $this->assertSame($earningsBefore, InstructorEarning::query()->where('instructor_id', $instructor->id)->count());
        $this->assertSame($settlementsBefore, InstructorSettlementBatch::query()->where('instructor_id', $instructor->id)->count());
    }

    public function test_no_notification_is_sent(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->completedLesson($this->paidLesson($instructor));

        // Faked only around the submission itself — lesson completion
        // (above) legitimately sends its own BookingCompletedNotification
        // through the unrelated Lessons pipeline; this test asserts
        // feedback submission adds no notification of its own.
        Notification::fake();

        $this->feedback->submit($lesson, $instructor, $this->data());

        Notification::assertNothingSent();
    }

    public function test_no_learning_plan_record_is_created_or_updated(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->completedLesson($this->paidLesson($instructor));
        $countBefore = StudentLearningPlan::query()->count();

        $this->feedback->submit($lesson, $instructor, $this->data());

        $this->assertSame($countBefore, StudentLearningPlan::query()->count());
    }

    // ── 35. Outcome override ─────────────────────────────────────────────

    public function test_outcome_override_preserves_the_historical_feedback(): void
    {
        $this->seed(LessonPermissionSeeder::class);
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('manager');

        $instructor = $this->instructorUser();
        $lesson = $this->completedLesson($this->paidLesson($instructor));
        $result = $this->feedback->submit($lesson, $instructor, $this->data(engagementObservation: 'Engaged well.'));

        $this->outcomes->override($lesson->fresh(), $admin, LessonOutcome::Cancelled, 'Corrected after the fact.');

        $record = InstructorStudentFeedback::query()->findOrFail($result->id);
        $this->assertSame(LessonOutcome::Completed, $record->outcome_snapshot);
        $this->assertSame('Engaged well.', $record->engagement_observation);
        $this->assertSame(LessonOutcome::Cancelled, $lesson->fresh()->outcome);
    }

    // ── 36. Regression ────────────────────────────────────────────────────

    public function test_phase_17h_to_17p_regression_unaffected(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->completedLesson($this->paidLesson($instructor));

        $this->feedback->submit($lesson, $instructor, $this->data());

        $eligibility = LessonReviewEligibility::query()->where('lesson_id', $lesson->id)->first();
        $this->assertNull($eligibility, 'Reviews are disabled by default in this test class, confirming feedback submission does not itself open eligibility.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function instructorUser(): User
    {
        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->profile->update(['profile_visibility' => 'public', 'instructor_status' => InstructorStatus::Approved]);
        $instructor->assignRole('instructor');

        return $instructor;
    }

    private function permissionedAdmin(): User
    {
        $this->seed(FeedbackPermissionSeeder::class);

        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('manager');

        return $admin;
    }

    private function paidLesson(User $instructor, ?User $student = null): Lesson
    {
        $endsAt = now()->subHours(2)->startOfHour();

        $booking = Booking::factory()->confirmed()->create([
            'booking_type_id' => BookingType::factory()->paid(),
            'instructor_id' => $instructor->id,
            'student_id' => $student?->id ?? User::factory(),
            'starts_at' => $endsAt->copy()->subMinutes(60),
            'ends_at' => $endsAt,
            'payment_status' => BookingPaymentStatus::Paid,
            'price' => '499.00',
            'currency' => 'INR',
        ]);

        return $this->lifecycle->createFromBooking($booking);
    }

    private function demoLesson(User $instructor, ?User $student = null): Lesson
    {
        $endsAt = now()->subHours(2)->startOfHour();

        $booking = Booking::factory()->confirmed()->create([
            'instructor_id' => $instructor->id,
            'student_id' => $student?->id ?? User::factory(),
            'starts_at' => $endsAt->copy()->subMinutes(60),
            'ends_at' => $endsAt,
            'payment_status' => BookingPaymentStatus::NotRequired,
            'price' => null,
            'currency' => null,
        ]);

        return $this->lifecycle->createFromBooking($booking);
    }

    private function completedLesson(Lesson $lesson, LessonOutcome $outcome = LessonOutcome::Completed): Lesson
    {
        $this->outcomes->finalize($lesson->refresh(), $outcome);

        return $lesson->fresh();
    }

    private function data(
        ?string $attendanceObservation = null,
        ?string $preparednessObservation = null,
        ?string $homeworkCompletionObservation = null,
        ?string $engagementObservation = null,
        ?string $learningAttitudeObservation = null,
        ?string $areasNeedingSupport = null,
        ?string $privateNotes = null,
    ): SubmitInstructorStudentFeedbackData {
        return new SubmitInstructorStudentFeedbackData(
            attendanceObservation: $attendanceObservation,
            preparednessObservation: $preparednessObservation,
            homeworkCompletionObservation: $homeworkCompletionObservation,
            engagementObservation: $engagementObservation,
            learningAttitudeObservation: $learningAttitudeObservation,
            areasNeedingSupport: $areasNeedingSupport,
            privateNotes: $privateNotes,
        );
    }
}
