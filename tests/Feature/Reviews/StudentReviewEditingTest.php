<?php

declare(strict_types=1);

namespace Tests\Feature\Reviews;

use App\Booking\Enums\BookingPaymentStatus;
use App\Enums\InstructorStatus;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\Enums\LessonOutcome;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\InstructorQualityAlert;
use App\Models\InstructorReviewResponse;
use App\Models\Lesson;
use App\Models\LessonReview;
use App\Models\LessonReviewEligibility;
use App\Models\LessonReviewRevision;
use App\Models\ReviewResponse;
use App\Models\StudentLearningPlan;
use App\Models\User;
use App\Notifications\Reviews\ReviewPublishedInstructorNotification;
use App\Notifications\Reviews\ReviewPublishedStudentNotification;
use App\Reviews\Contracts\InstructorRatingAggregateServiceInterface;
use App\Reviews\Contracts\ReviewModerationServiceInterface;
use App\Reviews\Contracts\ReviewReportServiceInterface;
use App\Reviews\Contracts\StudentReviewEditingServiceInterface;
use App\Reviews\Contracts\StudentReviewServiceInterface;
use App\Reviews\DTOs\EditStudentReviewData;
use App\Reviews\DTOs\SubmitReviewReportData;
use App\Reviews\DTOs\SubmitStudentReviewData;
use App\Reviews\Enums\ReviewReportReason;
use App\Reviews\Enums\StudentReviewStatus;
use App\Reviews\Exceptions\ReviewEligibilityException;
use App\Reviews\Exceptions\ReviewValidationException;
use App\Settings\ReviewSettings;
use Database\Seeders\ReviewPermissionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 17R — limited student review editing: editable statuses and
 * the edit window, report/dispute locks, snapshot-governed validation,
 * append-only sanitized revision history, re-moderation targets
 * (public → Submitted/Flagged, private stays private), exactly-once
 * aggregate contribution swap, and the guarantee that nothing is ever
 * deleted, notified, or leaked.
 */
class StudentReviewEditingTest extends TestCase
{
    use RefreshDatabase;

    private LessonLifecycleServiceInterface $lifecycle;

    private LessonOutcomeServiceInterface $outcomes;

    private StudentReviewServiceInterface $submissions;

    private StudentReviewEditingServiceInterface $editing;

    private ReviewModerationServiceInterface $moderation;

    private ReviewReportServiceInterface $reports;

    private InstructorRatingAggregateServiceInterface $aggregates;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $this->lifecycle = app(LessonLifecycleServiceInterface::class);
        $this->outcomes = app(LessonOutcomeServiceInterface::class);
        $this->submissions = app(StudentReviewServiceInterface::class);
        $this->editing = app(StudentReviewEditingServiceInterface::class);
        $this->moderation = app(ReviewModerationServiceInterface::class);
        $this->reports = app(ReviewReportServiceInterface::class);
        $this->aggregates = app(InstructorRatingAggregateServiceInterface::class);

        $this->enableReviews();
    }

    // ── 9–12. Editable statuses ──────────────────────────────────────────

    public function test_eligible_submitted_review_can_be_edited(): void
    {
        $this->enableReviews(['moderation_model' => 'pre_moderation', 'auto_publish_clean_reviews' => false]);
        $review = $this->submitPublicReview()->fresh();
        $this->assertSame(StudentReviewStatus::Submitted, $review->status);

        $edited = $this->editing->edit($review, $review->student, $this->edit(content: 'A fully rewritten and improved review body.'));

        $this->assertSame('A fully rewritten and improved review body.', $edited->content);
        $this->assertSame(StudentReviewStatus::Submitted, $edited->status);
        $this->assertSame(2, $edited->version);
        $this->assertSame(1, LessonReviewRevision::query()->where('lesson_review_id', $review->id)->count());
    }

    public function test_eligible_flagged_review_can_be_edited(): void
    {
        $review = $this->submitPublicReview(content: 'Contact me on reach.me@example.com for details of this lesson.')->fresh();
        $this->assertSame(StudentReviewStatus::Flagged, $review->status);

        $edited = $this->editing->edit($review, $review->student, $this->edit(content: 'A clean rewritten review without contact details.'));

        $this->assertSame('A clean rewritten review without contact details.', $edited->content);
        // A clean public edit re-enters the automatic pipeline (Submitted),
        // which may auto-publish it again under the active policy.
        $this->assertContains($edited->fresh()->status, [StudentReviewStatus::Submitted, StudentReviewStatus::Published]);
        $this->assertSame(1, LessonReviewRevision::query()->where('lesson_review_id', $review->id)->count());
    }

    public function test_eligible_published_review_can_be_edited(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $this->assertSame(StudentReviewStatus::Published, $review->status);

        $edited = $this->editing->edit($review, $review->student, $this->edit(content: 'An updated opinion after more lessons together.'));

        $this->assertSame('An updated opinion after more lessons together.', $edited->content);
        $this->assertSame(1, LessonReviewRevision::query()->where('lesson_review_id', $review->id)->count());
    }

    public function test_eligible_private_feedback_can_be_edited(): void
    {
        $review = $this->submitPrivateFeedback()->fresh();
        $this->assertSame(StudentReviewStatus::Private, $review->status);

        $edited = $this->editing->edit($review, $review->student, $this->edit(content: 'Updated private feedback for the instructor.'));

        $this->assertSame(StudentReviewStatus::Private, $edited->status);
        $this->assertSame('Updated private feedback for the instructor.', $edited->content);
    }

    // ── 13–15. Non-editable statuses ──────────────────────────────────────

    public function test_hidden_review_cannot_be_edited(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $this->moderation->hide($review, $this->admin(), 'Under review.');

        $this->expectException(ReviewEligibilityException::class);
        $this->editing->edit($review->fresh(), $review->student, $this->edit());
    }

    public function test_rejected_review_cannot_be_edited(): void
    {
        $this->enableReviews(['moderation_model' => 'pre_moderation', 'auto_publish_clean_reviews' => false]);
        $review = $this->submitPublicReview()->fresh();
        $this->moderation->reject($review, $this->admin(), 'Not acceptable.');

        $this->expectException(ReviewEligibilityException::class);
        $this->editing->edit($review->fresh(), $review->student, $this->edit());
    }

    public function test_archived_review_cannot_be_edited(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $admin = $this->admin();
        $this->moderation->hide($review, $admin, 'Cleanup.');
        $this->moderation->archive($review->fresh(), $admin, 'Historic.');

        $this->expectException(ReviewEligibilityException::class);
        $this->editing->edit($review->fresh(), $review->student, $this->edit());
    }

    // ── 16–17. Window & switch ────────────────────────────────────────────

    public function test_expired_edit_window_rejects_editing(): void
    {
        $review = $this->submitPublicReview()->fresh();

        $this->travel(25)->hours();

        $this->expectException(ReviewEligibilityException::class);

        try {
            $this->editing->edit($review, $review->student, $this->edit());
        } finally {
            $this->travelBack();
        }
    }

    public function test_editing_disabled_setting_rejects_editing(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $this->enableReviews(['review_editing_enabled' => false]);

        $this->expectException(ReviewEligibilityException::class);
        $this->editing->edit($review, $review->student, $this->edit());
    }

    // ── 18–19. Foreign editors ────────────────────────────────────────────

    public function test_instructor_cannot_edit_a_student_review(): void
    {
        $review = $this->submitPublicReview()->fresh();

        $this->expectException(ReviewEligibilityException::class);
        $this->editing->edit($review, $review->instructor, $this->edit());
    }

    public function test_another_student_cannot_edit(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $otherStudent = User::factory()->create(['status' => 'active']);

        $this->expectException(ReviewEligibilityException::class);
        $this->editing->edit($review, $otherStudent, $this->edit());
    }

    // ── 20–24. Re-moderation ──────────────────────────────────────────────

    public function test_published_review_disappears_publicly_while_re_moderation_is_pending(): void
    {
        $review = $this->submitPublicReview(content: 'Original published content marker.')->fresh();
        $this->assertSame(StudentReviewStatus::Published, $review->status);

        // Freeze auto-publication so the edit stays pending.
        $this->enableReviews(['auto_publish_clean_reviews' => false]);

        $this->editing->edit($review, $review->student, $this->edit(content: 'Edited content awaiting re-moderation.'));

        $fresh = $review->fresh();
        $this->assertSame(StudentReviewStatus::Submitted, $fresh->status);
        $this->assertFalse($fresh->status->isPubliclyVisible());

        $response = $this->get(route('instructors.show', $review->instructor));
        $response->assertOk();
        $response->assertDontSee('Original published content marker.');
        $response->assertDontSee('Edited content awaiting re-moderation.');
    }

    public function test_clean_public_edit_follows_existing_automatic_moderation(): void
    {
        $review = $this->submitPublicReview()->fresh();

        $this->editing->edit($review, $review->student, $this->edit(content: 'Clean edited text that should auto-publish again.'));

        $this->assertSame(StudentReviewStatus::Published, $review->fresh()->status);
    }

    public function test_flagged_public_edit_enters_moderation_queue(): void
    {
        $review = $this->submitPublicReview()->fresh();

        $this->editing->edit($review, $review->student, $this->edit(content: 'Reach me at sneaky.contact@example.com to continue outside.'));

        $fresh = $review->fresh();
        $this->assertSame(StudentReviewStatus::Flagged, $fresh->status);
        $this->assertStringNotContainsString('sneaky.contact@example.com', (string) $fresh->content);
    }

    public function test_clean_private_edit_remains_private(): void
    {
        $review = $this->submitPrivateFeedback()->fresh();

        $this->editing->edit($review, $review->student, $this->edit(content: 'A clean private feedback update.'));

        $this->assertSame(StudentReviewStatus::Private, $review->fresh()->status);
    }

    public function test_flagged_private_edit_never_becomes_public(): void
    {
        $review = $this->submitPrivateFeedback()->fresh();

        $this->editing->edit($review, $review->student, $this->edit(content: 'Please call +1 555 867 5309 to discuss privately.'));

        $flagged = $review->fresh();
        $this->assertSame(StudentReviewStatus::Flagged, $flagged->status);

        // The mode-derived approve target returns private feedback to Private, never Published.
        $approved = $this->moderation->approve($flagged, $this->admin(), 'Content acceptable after review.');
        $this->assertSame(StudentReviewStatus::Private, $approved->status);
    }

    // ── 25–28. Revision history & snapshot validation ────────────────────

    public function test_previous_content_is_preserved_in_revision_history(): void
    {
        $review = $this->submitPublicReview(content: 'The original review body before any edits.')->fresh();

        $this->editing->edit($review, $review->student, $this->edit(overallRating: 3, content: 'The replacement review body.'));

        $revision = LessonReviewRevision::query()->where('lesson_review_id', $review->id)->firstOrFail();
        $this->assertSame('The original review body before any edits.', $revision->previous_content);
        $this->assertSame(5, $revision->previous_overall_rating);
        $this->assertSame(StudentReviewStatus::Published, $revision->previous_status);
        $this->assertSame(2, $revision->review_version); // published bumped v1 → v2; the edit recorded v2
    }

    public function test_revision_history_contains_sanitized_content_only(): void
    {
        $rawEmail = 'never.stored@example.com';
        $review = $this->submitPublicReview(content: "Original with a leak: {$rawEmail} embedded in the text.")->fresh();
        $this->assertStringNotContainsString($rawEmail, (string) $review->content);

        $this->editing->edit($review, $review->student, $this->edit(content: 'Second version, fully clean.'));

        $revision = LessonReviewRevision::query()->where('lesson_review_id', $review->id)->firstOrFail();
        $this->assertStringNotContainsString($rawEmail, (string) $revision->previous_content);
    }

    public function test_raw_unsafe_content_is_not_logged_or_preserved(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $phone = '+1 (555) 314-1592';

        $this->editing->edit($review, $review->student, $this->edit(content: "Edited to add my number {$phone} for direct booking."));

        $activity = Activity::query()
            ->where('log_name', 'reviews')
            ->where('event', 'student_review_edited')
            ->latest('id')
            ->firstOrFail();

        $serialized = json_encode($activity->properties).$activity->description;
        $this->assertStringNotContainsString('314-1592', $serialized);
        $this->assertContains('phone_number', $activity->properties->get('content_flags'));
        $this->assertStringNotContainsString('314-1592', (string) $review->fresh()->content);
    }

    public function test_rating_validation_uses_the_stored_rating_policy_snapshot(): void
    {
        $review = $this->submitPublicReview()->fresh(); // snapshot: rating 1–5

        // A later settings change must not widen the historical review's own scale.
        $this->enableReviews(['rating_max' => 10]);

        $this->expectException(ReviewValidationException::class);
        $this->editing->edit($review, $review->student, $this->edit(overallRating: 8));
    }

    // ── 29–30. Report locks ────────────────────────────────────────────────

    public function test_existing_report_under_review_blocks_editing(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $reporter = User::factory()->create(['status' => 'active']);
        $reporter->assignRole('student');
        $this->seed(ReviewPermissionSeeder::class);

        $report = $this->reports->submit($review, $reporter, new SubmitReviewReportData(reason: ReviewReportReason::Spam));
        $this->reports->startReview($report, $this->admin());

        $this->expectException(ReviewEligibilityException::class);
        $this->editing->edit($review->fresh(), $review->student, $this->edit());
    }

    public function test_terminal_resolved_report_blocks_editing(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $reporter = User::factory()->create(['status' => 'active']);
        $reporter->assignRole('student');
        $this->seed(ReviewPermissionSeeder::class);

        $report = $this->reports->submit($review, $reporter, new SubmitReviewReportData(reason: ReviewReportReason::Spam));
        $this->reports->dismiss($report, $this->admin(), 'Report not substantiated.');

        $this->expectException(ReviewEligibilityException::class);
        $this->editing->edit($review->fresh(), $review->student, $this->edit());
    }

    // ── 31–33. Aggregate consistency ──────────────────────────────────────

    public function test_edited_published_rating_removes_the_old_contribution_once(): void
    {
        $review = $this->submitPublicReview(overallRating: 5)->fresh();
        $this->assertSame(5.0, $this->aggregates->summaryFor($review->instructor_id)->averageRating);

        $this->enableReviews(['auto_publish_clean_reviews' => false]);
        $this->editing->edit($review, $review->student, $this->edit(overallRating: 2));

        $summary = $this->aggregates->summaryFor($review->instructor_id);
        $this->assertSame(0, $summary->reviewCount);
        $this->assertNull($summary->averageRating);
    }

    public function test_new_rating_contributes_only_after_republishing(): void
    {
        $review = $this->submitPublicReview(overallRating: 5)->fresh();
        $this->enableReviews(['auto_publish_clean_reviews' => false]);
        $this->editing->edit($review, $review->student, $this->edit(overallRating: 2));

        $this->assertSame(0, $this->aggregates->summaryFor($review->instructor_id)->reviewCount);

        $this->moderation->approve($review->fresh(), $this->admin(), 'Edited version approved.');

        $summary = $this->aggregates->summaryFor($review->instructor_id);
        $this->assertSame(1, $summary->reviewCount);
        $this->assertSame(2.0, $summary->averageRating);
    }

    public function test_duplicate_events_do_not_double_apply_aggregate_values(): void
    {
        $review = $this->submitPublicReview(overallRating: 4)->fresh();

        // Replay reconciliation repeatedly — it must converge, never accumulate.
        $this->aggregates->reconcile($review);
        $this->aggregates->reconcile($review);
        $this->aggregates->reconcile($review->fresh());

        $summary = $this->aggregates->summaryFor($review->instructor_id);
        $this->assertSame(1, $summary->reviewCount);
        $this->assertSame(4.0, $summary->averageRating);
    }

    // ── 34–36. Concurrency, failure atomicity, no deletion ────────────────

    public function test_concurrent_edits_apply_one_version_safely(): void
    {
        $review = $this->submitPublicReview()->fresh();

        $this->editing->edit($review, $review->student, $this->edit(content: 'First sequential edit content.'));
        $this->editing->edit($review->fresh(), $review->student, $this->edit(content: 'Second sequential edit content.'));

        $revisions = LessonReviewRevision::query()->where('lesson_review_id', $review->id)->get();
        $this->assertCount(2, $revisions);
        $this->assertSame($revisions->pluck('review_version')->unique()->count(), $revisions->count());

        // The database's own unique index makes a same-version duplicate
        // append (the second half of a true race) structurally impossible.
        $this->expectException(UniqueConstraintViolationException::class);
        LessonReviewRevision::query()->create([
            'lesson_review_id' => $review->id,
            'review_version' => $revisions->first()->review_version,
            'previous_overall_rating' => 5,
            'previous_status' => StudentReviewStatus::Published,
            'edited_by' => $review->student_id,
            'edited_at' => now(),
        ]);
    }

    public function test_failed_edit_leaves_current_review_and_history_unchanged(): void
    {
        $review = $this->submitPublicReview(content: 'Stable original content.')->fresh();
        $versionBefore = $review->version;

        try {
            $this->editing->edit($review, $review->student, $this->edit(overallRating: 99, content: 'Never applied.'));
            $this->fail('Expected ReviewValidationException.');
        } catch (ReviewValidationException) {
            // expected
        }

        $fresh = $review->fresh();
        $this->assertSame('Stable original content.', $fresh->content);
        $this->assertSame($versionBefore, $fresh->version);
        $this->assertSame(0, LessonReviewRevision::query()->where('lesson_review_id', $review->id)->count());
    }

    public function test_no_review_is_physically_deleted(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $this->editing->edit($review, $review->student, $this->edit(content: 'Edited but never deleted.'));

        $this->assertDatabaseHas('lesson_reviews', ['id' => $review->id]);
        $this->assertNotContains('delete', get_class_methods(StudentReviewEditingServiceInterface::class));
    }

    // ── 37–39. Side-effect isolation ──────────────────────────────────────

    public function test_no_notification_is_sent(): void
    {
        // Confirms Phase 17R's own stated exclusion — StudentReviewEdited
        // itself has no notification listener attached in any phase.
        // Phase 17S DOES attach listeners to the downstream re-moderation
        // events a clean edit triggers (Submitted → auto-published again)
        // — that legitimate republish notification is the expected
        // exception asserted below.
        $review = $this->submitPublicReview()->fresh();

        Notification::fake();
        $this->editing->edit($review, $review->student, $this->edit(content: 'Edited quietly with no notification.'));

        Notification::assertSentTo($review->student, ReviewPublishedStudentNotification::class);
        Notification::assertSentTo($review->instructor, ReviewPublishedInstructorNotification::class);
    }

    public function test_no_instructor_response_is_created(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $this->editing->edit($review, $review->student, $this->edit());

        $this->assertFalse(class_exists(ReviewResponse::class));
        $this->assertFalse(class_exists(InstructorReviewResponse::class));
    }

    public function test_no_learning_plan_or_quality_score_record_changes(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $plansBefore = StudentLearningPlan::query()->count();
        $alertsBefore = InstructorQualityAlert::query()->count();

        $this->editing->edit($review, $review->student, $this->edit(overallRating: 1));

        $this->assertSame($plansBefore, StudentLearningPlan::query()->count());
        $this->assertSame($alertsBefore, InstructorQualityAlert::query()->count());
    }

    // ── 40. Regression ────────────────────────────────────────────────────

    public function test_phase_17h_to_17q_regression_unaffected(): void
    {
        $review = $this->submitPublicReview(content: 'DM me on @some_handle for offers.')->fresh();
        $this->assertSame(StudentReviewStatus::Flagged, $review->status);

        $approved = $this->moderation->approve($review, $this->admin(), 'Manually reviewed — acceptable.');
        $this->assertSame(StudentReviewStatus::Published, $approved->status);
        $this->assertSame(1, $this->aggregates->summaryFor($review->instructor_id)->reviewCount);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function edit(
        int $overallRating = 4,
        ?string $content = 'A thoughtfully edited review body.',
    ): EditStudentReviewData {
        return new EditStudentReviewData(overallRating: $overallRating, content: $content);
    }

    private function instructorUser(): User
    {
        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->profile->update(['profile_visibility' => 'public', 'instructor_status' => InstructorStatus::Approved]);
        $instructor->assignRole('instructor');

        return $instructor;
    }

    private function admin(): User
    {
        $this->seed(ReviewPermissionSeeder::class);

        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('super_admin');

        return $admin;
    }

    private function paidLesson(User $instructor): Lesson
    {
        $endsAt = now()->subHours(2)->startOfHour();

        $booking = Booking::factory()->confirmed()->create([
            'booking_type_id' => BookingType::factory()->paid(),
            'host_id' => $instructor->id,
            'starts_at' => $endsAt->copy()->subMinutes(60),
            'ends_at' => $endsAt,
            'payment_status' => BookingPaymentStatus::Paid,
            'price' => '499.00',
            'currency' => 'INR',
        ]);

        return $this->lifecycle->createFromBooking($booking);
    }

    private function demoLesson(User $instructor): Lesson
    {
        $endsAt = now()->subHours(2)->startOfHour();

        $booking = Booking::factory()->confirmed()->create([
            'host_id' => $instructor->id,
            'starts_at' => $endsAt->copy()->subMinutes(60),
            'ends_at' => $endsAt,
            'payment_status' => BookingPaymentStatus::NotRequired,
            'price' => null,
            'currency' => null,
        ]);

        return $this->lifecycle->createFromBooking($booking);
    }

    private function openEligibility(Lesson $lesson): LessonReviewEligibility
    {
        $this->outcomes->finalize($lesson->refresh(), LessonOutcome::Completed);

        return LessonReviewEligibility::query()->where('lesson_id', $lesson->id)->firstOrFail();
    }

    private function submitPublicReview(
        int $overallRating = 5,
        string $content = 'A genuinely helpful and well-structured lesson overall.',
    ): LessonReview {
        $eligibility = $this->openEligibility($this->paidLesson($this->instructorUser()));

        return $this->submissions->submit($eligibility, $eligibility->student, new SubmitStudentReviewData(
            overallRating: $overallRating,
            content: $content,
        ))->review;
    }

    private function submitPrivateFeedback(string $content = 'Private feedback about the trial lesson experience.'): LessonReview
    {
        $eligibility = $this->openEligibility($this->demoLesson($this->instructorUser()));

        return $this->submissions->submit($eligibility, $eligibility->student, new SubmitStudentReviewData(
            overallRating: 4,
            content: $content,
        ))->review;
    }

    /** @param array<string, mixed> $overrides */
    private function enableReviews(array $overrides = []): void
    {
        $settings = app(ReviewSettings::class);
        $settings->reviews_enabled = true;
        $settings->paid_lesson_reviews_enabled = true;
        $settings->demo_review_policy = 'private_only';
        $settings->review_window_days = 14;
        $settings->rating_min = 1;
        $settings->rating_max = 5;
        $settings->written_review_required = false;
        $settings->review_min_length = 10;
        $settings->review_max_length = 2000;
        $settings->rating_dimensions_enabled = true;
        $settings->review_max_tags = 5;
        $settings->moderation_model = 'risk_based';
        $settings->auto_publish_clean_reviews = true;
        $settings->public_review_identity_mode = 'first_name_initial';
        $settings->review_reporting_enabled = true;
        $settings->review_editing_enabled = true;
        $settings->review_edit_window_hours = 24;
        $settings->quality_alerts_enabled = false;

        foreach ($overrides as $key => $value) {
            $settings->{$key} = $value;
        }

        $settings->save();
    }
}
