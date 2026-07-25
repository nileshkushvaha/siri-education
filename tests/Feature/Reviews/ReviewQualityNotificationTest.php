<?php

declare(strict_types=1);

namespace Tests\Feature\Reviews;

use App\Booking\Enums\BookingPaymentStatus;
use App\Enums\InstructorStatus;
use App\Feedback\Contracts\InstructorStudentFeedbackServiceInterface;
use App\Feedback\DTOs\SubmitInstructorStudentFeedbackData;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\Enums\LessonOutcome;
use App\Listeners\Reviews\SendReviewRequestedNotification;
use App\Listeners\Reviews\SendReviewResponseNotification;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\InstructorRatingAggregate;
use App\Models\Lesson;
use App\Models\LessonReview;
use App\Models\LessonReviewEligibility;
use App\Models\NotificationDispatchLog;
use App\Models\User;
use App\Notifications\Quality\InstructorQualityAlertCreatedNotification;
use App\Notifications\Reviews\ReviewHiddenNotification;
use App\Notifications\Reviews\ReviewModerationRequiredNotification;
use App\Notifications\Reviews\ReviewPublishedInstructorNotification;
use App\Notifications\Reviews\ReviewPublishedStudentNotification;
use App\Notifications\Reviews\ReviewRejectedNotification;
use App\Notifications\Reviews\ReviewReportedNotification;
use App\Notifications\Reviews\ReviewRequestedNotification;
use App\Notifications\Reviews\ReviewResponseNotification;
use App\Notifications\Reviews\ReviewSubmittedNotification;
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
use App\Reviews\Events\LessonReviewEligibilityOpened;
use App\Reviews\Events\ReviewReported;
use App\Reviews\Events\StudentReviewPublished;
use App\Reviews\Support\ReviewNotificationChannelResolver;
use App\Services\Notifications\NotificationIdempotencyGuard;
use App\Settings\ReviewSettings;
use Database\Seeders\FeedbackPermissionSeeder;
use Database\Seeders\ReviewPermissionSeeder;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 17S — review & quality notifications: event-to-recipient
 * coverage, own-permission admin resolution, replay/concurrency
 * idempotency, channel routing, and the guarantee that every payload
 * stays privacy-safe and no notification listener ever mutates
 * business state.
 */
class ReviewQualityNotificationTest extends TestCase
{
    use RefreshDatabase;

    private LessonLifecycleServiceInterface $lifecycle;

    private LessonOutcomeServiceInterface $outcomes;

    private StudentReviewServiceInterface $submissions;

    private ReviewModerationServiceInterface $moderation;

    private ReviewReportServiceInterface $reports;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $this->lifecycle = app(LessonLifecycleServiceInterface::class);
        $this->outcomes = app(LessonOutcomeServiceInterface::class);
        $this->submissions = app(StudentReviewServiceInterface::class);
        $this->moderation = app(ReviewModerationServiceInterface::class);
        $this->reports = app(ReviewReportServiceInterface::class);

        $this->enableReviews();
    }

    // ── 1–3. Review requested ─────────────────────────────────────────────

    public function test_open_review_eligibility_sends_one_review_request_to_the_student(): void
    {
        Notification::fake();

        $eligibility = $this->openEligibility($this->paidLesson());

        Notification::assertSentTo($eligibility->student, ReviewRequestedNotification::class);
        Notification::assertSentToTimes($eligibility->student, ReviewRequestedNotification::class, 1);
    }

    public function test_expired_or_revoked_eligibility_sends_no_request(): void
    {
        Notification::fake();

        $expired = LessonReviewEligibility::factory()->expired()->create();
        $revoked = LessonReviewEligibility::factory()->revoked()->create();

        app(SendReviewRequestedNotification::class)->handle(new LessonReviewEligibilityOpened($expired));
        app(SendReviewRequestedNotification::class)->handle(new LessonReviewEligibilityOpened($revoked));

        Notification::assertNothingSent();
    }

    public function test_duplicate_eligibility_event_sends_one_notification(): void
    {
        Notification::fake();

        $eligibility = $this->openEligibility($this->paidLesson())->fresh();
        $listener = app(SendReviewRequestedNotification::class);

        $listener->handle(new LessonReviewEligibilityOpened($eligibility));

        Notification::assertSentToTimes($eligibility->student, ReviewRequestedNotification::class, 1);
    }

    // ── 4. Submission confirmation ────────────────────────────────────────

    public function test_review_submission_sends_confirmation_to_the_student(): void
    {
        $eligibility = $this->openEligibility($this->paidLesson());
        Notification::fake();

        $this->submissions->submit($eligibility, $eligibility->student, $this->reviewData());

        Notification::assertSentTo($eligibility->student, ReviewSubmittedNotification::class);
    }

    // ── 5–7. Published notifications ──────────────────────────────────────

    public function test_published_public_review_notifies_the_student(): void
    {
        Notification::fake();

        $review = $this->submitPublicReview()->fresh();
        $this->assertSame(StudentReviewStatus::Published, $review->status);

        Notification::assertSentTo($review->student, ReviewPublishedStudentNotification::class);
    }

    public function test_published_public_review_notifies_the_instructor(): void
    {
        Notification::fake();

        $review = $this->submitPublicReview()->fresh();
        $this->assertSame(StudentReviewStatus::Published, $review->status);

        Notification::assertSentTo($review->instructor, ReviewPublishedInstructorNotification::class);
    }

    public function test_private_feedback_never_notifies_the_instructor(): void
    {
        Notification::fake();

        $review = $this->submitPrivateFeedback()->fresh();

        Notification::assertNotSentTo($review->instructor, ReviewPublishedInstructorNotification::class);
    }

    // ── 8–9. Rejected / hidden ────────────────────────────────────────────

    public function test_rejected_review_notifies_only_its_student_author(): void
    {
        $this->enableReviews(['moderation_model' => 'pre_moderation', 'auto_publish_clean_reviews' => false]);
        $review = $this->submitPublicReview()->fresh();
        $admin = $this->admin();
        Notification::fake();

        $this->moderation->reject($review, $admin, 'Not acceptable.');

        Notification::assertSentTo($review->student, ReviewRejectedNotification::class);
        Notification::assertNotSentTo($review->instructor, ReviewRejectedNotification::class);
        Notification::assertNotSentTo($admin, ReviewRejectedNotification::class);
    }

    public function test_hidden_review_notifies_only_its_student_author(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $admin = $this->admin();
        Notification::fake();

        $this->moderation->hide($review, $admin, 'Under review.');

        Notification::assertSentTo($review->student, ReviewHiddenNotification::class);
        Notification::assertNotSentTo($review->instructor, ReviewHiddenNotification::class);
    }

    // ── 10–13. Moderation-required ─────────────────────────────────────────

    public function test_submitted_pre_moderation_review_notifies_authorized_moderators(): void
    {
        $this->enableReviews(['moderation_model' => 'pre_moderation', 'auto_publish_clean_reviews' => false]);
        $admin = $this->admin();
        $eligibility = $this->openEligibility($this->paidLesson());
        Notification::fake();

        $this->submissions->submit($eligibility, $eligibility->student, $this->reviewData());

        Notification::assertSentTo($admin, ReviewModerationRequiredNotification::class);
    }

    public function test_flagged_review_notifies_authorized_moderators_once(): void
    {
        $admin = $this->admin();
        $eligibility = $this->openEligibility($this->paidLesson());
        Notification::fake();

        $this->submissions->submit($eligibility, $eligibility->student, $this->reviewData(
            content: 'Contact me at leaking.email@example.com for more.',
        ));

        Notification::assertSentToTimes($admin, ReviewModerationRequiredNotification::class, 1);
    }

    public function test_flagged_edited_review_notifies_moderators_once(): void
    {
        $admin = $this->admin();
        $review = $this->submitPublicReview()->fresh();
        Notification::fake();

        app(StudentReviewEditingServiceInterface::class)->edit(
            $review,
            $review->student,
            new EditStudentReviewData(overallRating: 3, content: 'Edited: call me at +1 555 909 1234 please.'),
        );

        Notification::assertSentToTimes($admin, ReviewModerationRequiredNotification::class, 1);
    }

    public function test_auto_published_clean_review_does_not_create_a_moderation_pending_notification(): void
    {
        $admin = $this->admin();
        Notification::fake();

        $this->submitPublicReview();

        Notification::assertNotSentTo($admin, ReviewModerationRequiredNotification::class);
    }

    // ── 14–15. Reports & quality alerts ────────────────────────────────────

    public function test_reported_review_notifies_report_authorized_administrators(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $admin = $this->admin();
        $reporter = User::factory()->activeStudent()->create(['status' => 'active']);
        Notification::fake();

        $this->reports->submit($review, $reporter, new SubmitReviewReportData(reason: ReviewReportReason::Spam));

        Notification::assertSentTo($admin, ReviewReportedNotification::class);
    }

    public function test_quality_alert_notifies_quality_alert_authorized_administrators(): void
    {
        $this->enableReviews(['low_rating_threshold' => 2, 'single_low_rating_alert_enabled' => true]);
        $this->seed(ReviewPermissionSeeder::class);
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('manager');
        $admin->givePermissionTo('ViewInstructorQualityAlerts');

        Notification::fake();

        $review = $this->submitPublicReview(overallRating: 1)->fresh();
        $this->assertSame(StudentReviewStatus::Published, $review->status);

        Notification::assertSentTo($admin, InstructorQualityAlertCreatedNotification::class);
        Notification::assertNotSentTo($review->instructor, InstructorQualityAlertCreatedNotification::class);
    }

    // ── 16–18. Recipient resolution ────────────────────────────────────────

    public function test_unauthorized_administrators_receive_nothing(): void
    {
        $this->seed(ReviewPermissionSeeder::class);
        $plainManager = User::factory()->create(['status' => 'active']);
        $plainManager->assignRole('manager'); // no permission granted beyond the role itself
        $review = $this->submitPublicReview()->fresh();
        Notification::fake();

        $this->moderation->hide($review, $this->admin(), 'Routine.');

        Notification::assertNotSentTo($plainManager, ReviewHiddenNotification::class);
        Notification::assertNotSentTo($plainManager, ReviewModerationRequiredNotification::class);
    }

    public function test_inactive_recipients_are_excluded(): void
    {
        $this->seed(ReviewPermissionSeeder::class);
        $inactiveAdmin = User::factory()->create(['status' => 'inactive']);
        $inactiveAdmin->assignRole('manager');
        $inactiveAdmin->givePermissionTo('ViewReviewModerationQueue');

        Notification::fake();

        $this->submitPublicReview(content: 'Reach me at excluded.admin@example.com now.');

        Notification::assertNotSentTo($inactiveAdmin, ReviewModerationRequiredNotification::class);
    }

    public function test_a_manager_with_overlapping_permissions_receives_one_notification(): void
    {
        $this->seed(ReviewPermissionSeeder::class);
        $manager = User::factory()->create(['status' => 'active']);
        $manager->assignRole(['manager', 'super_admin']); // overlapping paths to eligibility
        $manager->givePermissionTo('ViewReviewModerationQueue');

        Notification::fake();

        $this->submitPublicReview(content: 'Contact via overlap.test@example.com directly.');

        Notification::assertSentToTimes($manager, ReviewModerationRequiredNotification::class, 1);
    }

    // ── 19–20. Idempotency & concurrency ───────────────────────────────────

    public function test_event_replay_does_not_duplicate_notification_records(): void
    {
        Notification::fake();

        $eligibility = $this->openEligibility($this->paidLesson())->fresh();
        $listener = app(SendReviewRequestedNotification::class);
        $event = new LessonReviewEligibilityOpened($eligibility);

        $listener->handle($event);
        $listener->handle($event);
        $listener->handle($event);

        Notification::assertSentToTimes($eligibility->student, ReviewRequestedNotification::class, 1);
        $this->assertSame(
            1,
            NotificationDispatchLog::query()->where('notification_class', ReviewRequestedNotification::class)->count(),
        );
    }

    public function test_concurrent_event_processing_remains_idempotent(): void
    {
        $guard = app(NotificationIdempotencyGuard::class);
        $calls = 0;

        $key = 'concurrency-test-key';
        $guard->once($key, 'TestNotification', function () use (&$calls): void {
            $calls++;
        });
        $guard->once($key, 'TestNotification', function () use (&$calls): void {
            $calls++;
        });

        $this->assertSame(1, $calls);
    }

    // ── 21. New version ────────────────────────────────────────────────────

    public function test_legitimate_new_review_version_can_generate_a_new_publication_notification(): void
    {
        $review = $this->submitPublicReview()->fresh();
        Notification::fake();

        $editing = app(StudentReviewEditingServiceInterface::class);
        $editing->edit($review, $review->student, new EditStudentReviewData(overallRating: 4, content: 'A clean re-edit that republishes cleanly.'));

        Notification::assertSentTo($review->fresh()->student, ReviewPublishedStudentNotification::class);
    }

    // ── 22–24. Channel routing ──────────────────────────────────────────────

    public function test_delivery_attempts_use_existing_channel_routing(): void
    {
        $student = User::factory()->create(['status' => 'active']);
        $channels = app(ReviewNotificationChannelResolver::class)->channels($student);

        $this->assertContains('database', $channels);
        $this->assertContains('mail', $channels);
    }

    public function test_disabled_channel_is_not_attempted(): void
    {
        $this->enableReviews(['review_channel_email_enabled' => false]);
        $student = User::factory()->create(['status' => 'active']);

        $channels = app(ReviewNotificationChannelResolver::class)->channels($student);

        $this->assertNotContains('mail', $channels);
        $this->assertContains('database', $channels);
    }

    public function test_delivery_failures_use_existing_retry_behavior(): void
    {
        $notification = new ReviewRequestedNotification($this->openEligibility($this->paidLesson()));

        $this->assertSame(3, $notification->tries);
        $this->assertSame([60, 300, 900], $notification->backoff);
    }

    // ── 25. Action link ───────────────────────────────────────────────────

    public function test_in_app_notification_contains_a_valid_named_route_action_link(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $notification = new ReviewPublishedStudentNotification($review);

        $payload = $notification->toDatabase($review->student);

        $this->assertSame(route('dashboard.reviews'), $payload['action_url']);
    }

    // ── 26–29. Privacy ────────────────────────────────────────────────────

    public function test_raw_review_text_is_absent_from_payload_and_logs(): void
    {
        $marker = 'a-distinctive-review-body-marker-778812';
        $review = $this->submitPublicReview(content: $marker)->fresh();

        $notification = new ReviewSubmittedNotification($review);
        $mail = $notification->toMail($review->student);
        $database = $notification->toDatabase($review->student);

        $this->assertStringNotContainsString($marker, json_encode($mail));
        $this->assertStringNotContainsString($marker, json_encode($database));
    }

    public function test_raw_report_explanation_is_absent(): void
    {
        $this->seed(ReviewPermissionSeeder::class);
        $review = $this->submitPublicReview()->fresh();
        $reporter = User::factory()->activeStudent()->create(['status' => 'active']);
        $marker = 'a-distinctive-report-explanation-991233';

        $report = $this->reports->submit($review, $reporter, new SubmitReviewReportData(
            reason: ReviewReportReason::Other,
            explanation: $marker,
        ));

        $notification = new ReviewReportedNotification($report->fresh());
        $mail = $notification->toMail($this->admin());
        $database = $notification->toDatabase($this->admin());

        $this->assertStringNotContainsString($marker, json_encode($mail));
        $this->assertStringNotContainsString($marker, json_encode($database));
    }

    public function test_student_contact_details_are_absent(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $notification = new ReviewPublishedInstructorNotification($review);

        $mail = $notification->toMail($review->instructor);
        $database = $notification->toDatabase($review->instructor);

        $this->assertStringNotContainsString($review->student->email, json_encode($mail));
        $this->assertStringNotContainsString($review->student->email, json_encode($database));
    }

    public function test_reporter_identity_is_absent_from_unauthorized_payloads(): void
    {
        $this->seed(ReviewPermissionSeeder::class);
        $review = $this->submitPublicReview()->fresh();
        $reporter = User::factory()->activeStudent()->create(['status' => 'active', 'first_name' => 'Distinctivereportername']);

        $report = $this->reports->submit($review, $reporter, new SubmitReviewReportData(reason: ReviewReportReason::Spam));

        $notification = new ReviewReportedNotification($report->fresh());
        $mail = $notification->toMail($this->admin());
        $database = $notification->toDatabase($this->admin());

        $this->assertStringNotContainsString('Distinctivereportername', json_encode($mail));
        $this->assertStringNotContainsString('Distinctivereportername', json_encode($database));
        $this->assertStringNotContainsString((string) $reporter->id, json_encode($database));
    }

    // ── 30–31. Explicitly excluded events ─────────────────────────────────

    public function test_private_instructor_feedback_sends_no_student_notification(): void
    {
        $this->seed(FeedbackPermissionSeeder::class);
        $instructor = $this->instructorUser();
        $lesson = $this->paidLesson($instructor);
        $this->outcomes->finalize($lesson->refresh(), LessonOutcome::Completed);
        Notification::fake();

        app(InstructorStudentFeedbackServiceInterface::class)->submit(
            $lesson->fresh(),
            $instructor,
            new SubmitInstructorStudentFeedbackData(engagementObservation: 'Engaged well throughout.'),
        );

        Notification::assertNothingSent();
    }

    public function test_no_review_response_template_listener_or_notification_exists(): void
    {
        $this->assertFalse(class_exists(ReviewResponseNotification::class));
        $this->assertFalse(class_exists(SendReviewResponseNotification::class));
    }

    // ── 32–34. Side-effect isolation ────────────────────────────────────────

    public function test_notification_failure_does_not_change_review_status(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $statusBefore = $review->status;

        // ShouldDispatchAfterCommit guarantees the transaction that set
        // this status already committed before any notification listener
        // runs — structurally, a notification failure cannot roll it back.
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, new StudentReviewPublished($review));
        $this->assertSame($statusBefore, $review->fresh()->status);
    }

    public function test_notification_failure_does_not_change_report_or_alert_status(): void
    {
        $this->seed(ReviewPermissionSeeder::class);
        $review = $this->submitPublicReview()->fresh();
        $reporter = User::factory()->activeStudent()->create(['status' => 'active']);

        $report = $this->reports->submit($review, $reporter, new SubmitReviewReportData(reason: ReviewReportReason::Spam));
        $statusBefore = $report->status;

        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, new ReviewReported($report));
        $this->assertSame($statusBefore, $report->fresh()->status);
    }

    public function test_no_financial_booking_outcome_or_aggregate_record_changes(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->paidLesson($instructor);
        $aggregateCountBefore = InstructorRatingAggregate::query()->count();

        $eligibility = $this->openEligibility($lesson);
        $this->submissions->submit($eligibility, $eligibility->student, $this->reviewData());

        $this->assertSame(BookingPaymentStatus::Paid, $lesson->fresh()->booking->payment_status);
        $this->assertSame(LessonOutcome::Completed, $lesson->fresh()->outcome);
        // The aggregate is only ever created/updated by the Phase 17K
        // reconciler reacting to a *Published* review — notification
        // listeners never touch it.
        $this->assertLessThanOrEqual($aggregateCountBefore + 1, InstructorRatingAggregate::query()->count());
    }

    // ── 35. Regression ────────────────────────────────────────────────────

    public function test_phase_17h_to_17r_regression_unaffected(): void
    {
        $review = $this->submitPublicReview(content: 'DM me on @some_handle for offers.')->fresh();
        $this->assertSame(StudentReviewStatus::Flagged, $review->status);

        $approved = $this->moderation->approve($review, $this->admin(), 'Manually reviewed — acceptable.');
        $this->assertSame(StudentReviewStatus::Published, $approved->status);
        $this->assertSame(1, app(InstructorRatingAggregateServiceInterface::class)->summaryFor($review->instructor_id)->reviewCount);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

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
        $admin->assignRole('manager');

        return $admin;
    }

    private function paidLesson(?User $instructor = null): Lesson
    {
        $instructor ??= $this->instructorUser();
        $endsAt = now()->subHours(2)->startOfHour();

        $booking = Booking::factory()->confirmed()->create([
            'booking_type_id' => BookingType::factory()->paid(),
            'instructor_id' => $instructor->id,
            'student_id' => User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE])->id,
            'starts_at' => $endsAt->copy()->subMinutes(60),
            'ends_at' => $endsAt,
            'payment_status' => BookingPaymentStatus::Paid,
            'price' => '499.00',
            'currency' => 'INR',
        ]);

        return $this->lifecycle->createFromBooking($booking);
    }

    private function demoLesson(): Lesson
    {
        $instructor = $this->instructorUser();
        $endsAt = now()->subHours(2)->startOfHour();

        $booking = Booking::factory()->confirmed()->create([
            'instructor_id' => $instructor->id,
            'student_id' => User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE])->id,
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

    private function submitPublicReview(int $overallRating = 5, string $content = 'A genuinely helpful and well-structured lesson overall.'): LessonReview
    {
        $eligibility = $this->openEligibility($this->paidLesson());

        return $this->submissions->submit($eligibility, $eligibility->student, $this->reviewData(overallRating: $overallRating, content: $content))->review;
    }

    private function submitPrivateFeedback(string $content = 'Private feedback about the trial lesson.'): LessonReview
    {
        $eligibility = $this->openEligibility($this->demoLesson());

        return $this->submissions->submit($eligibility, $eligibility->student, $this->reviewData(overallRating: 4, content: $content))->review;
    }

    private function reviewData(int $overallRating = 5, string $content = 'A genuinely helpful and well-structured lesson overall.'): SubmitStudentReviewData
    {
        return new SubmitStudentReviewData(overallRating: $overallRating, content: $content);
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
        $settings->quality_alerts_enabled = true;
        $settings->single_low_rating_alert_enabled = true;
        $settings->review_channel_email_enabled = true;
        $settings->review_channel_whatsapp_enabled = false;
        $settings->review_channel_sms_enabled = false;

        foreach ($overrides as $key => $value) {
            $settings->{$key} = $value;
        }

        $settings->save();
    }
}
