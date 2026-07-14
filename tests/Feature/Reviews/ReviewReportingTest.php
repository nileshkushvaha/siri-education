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
use App\Models\Lesson;
use App\Models\LessonReview;
use App\Models\LessonReviewEligibility;
use App\Models\ReviewReport;
use App\Models\User;
use App\Notifications\Reviews\ReviewHiddenNotification;
use App\Reviews\Contracts\InstructorRatingAggregateServiceInterface;
use App\Reviews\Contracts\PublicInstructorReviewServiceInterface;
use App\Reviews\Contracts\ReviewModerationServiceInterface;
use App\Reviews\Contracts\ReviewReportServiceInterface;
use App\Reviews\Contracts\StudentReviewServiceInterface;
use App\Reviews\DTOs\SubmitReviewReportData;
use App\Reviews\DTOs\SubmitStudentReviewData;
use App\Reviews\Enums\ReviewReportReason;
use App\Reviews\Enums\ReviewReportResolutionAction;
use App\Reviews\Enums\ReviewReportStatus;
use App\Reviews\Enums\StudentReviewStatus;
use App\Reviews\Exceptions\DuplicateReviewReportException;
use App\Reviews\Exceptions\InvalidReviewReportTransitionException;
use App\Reviews\Exceptions\ReviewNotReportableException;
use App\Reviews\Exceptions\ReviewValidationException;
use App\Settings\ReviewSettings;
use Database\Seeders\ReviewPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 17M — review reporting & administrative resolution: reporting
 * eligibility, duplicate/contact-leakage protection, the admin
 * start/uphold/dismiss/duplicate lifecycle delegating every review-
 * status change to the existing ReviewModerationService, idempotency/
 * concurrency, reporter-privacy, and the guarantee that nothing here
 * notifies, scores quality, or touches any financial/lesson record.
 */
class ReviewReportingTest extends TestCase
{
    use RefreshDatabase;

    private LessonLifecycleServiceInterface $lifecycle;

    private LessonOutcomeServiceInterface $outcomes;

    private StudentReviewServiceInterface $submissions;

    private ReviewModerationServiceInterface $moderation;

    private ReviewReportServiceInterface $reports;

    private InstructorRatingAggregateServiceInterface $ratings;

    private PublicInstructorReviewServiceInterface $publicReviews;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lifecycle = app(LessonLifecycleServiceInterface::class);
        $this->outcomes = app(LessonOutcomeServiceInterface::class);
        $this->submissions = app(StudentReviewServiceInterface::class);
        $this->moderation = app(ReviewModerationServiceInterface::class);
        $this->reports = app(ReviewReportServiceInterface::class);
        $this->ratings = app(InstructorRatingAggregateServiceInterface::class);
        $this->publicReviews = app(PublicInstructorReviewServiceInterface::class);

        $this->enableReviews();
        $this->seed(ReviewPermissionSeeder::class);
    }

    // ── 1. Happy path ────────────────────────────────────────────────────

    public function test_authenticated_user_can_report_a_published_public_review(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $reporter = $this->reporterUser();

        $report = $this->reports->submit($review, $reporter, new SubmitReviewReportData(
            reason: ReviewReportReason::AbusiveLanguage,
            explanation: 'This contains inappropriate language.',
        ));

        $this->assertSame(ReviewReportStatus::Pending, $report->status);
        $this->assertSame($review->id, $report->review_id);
        $this->assertSame($reporter->id, $report->reporter_id);
        $this->assertNotNull($report->submitted_at);
    }

    // ── 2–6. Reporting eligibility ────────────────────────────────────────

    public function test_private_feedback_cannot_be_reported(): void
    {
        $review = $this->submitPrivateFeedback()->fresh();
        $reporter = $this->reporterUser();

        $this->expectException(ReviewNotReportableException::class);
        $this->reports->submit($review, $reporter, new SubmitReviewReportData(reason: ReviewReportReason::Spam));
    }

    public function test_submitted_review_cannot_be_reported(): void
    {
        $this->enableReviews(['moderation_model' => 'pre_moderation']);
        $review = $this->submitPublicReview()->fresh(); // stays Submitted
        $reporter = $this->reporterUser();

        $this->expectException(ReviewNotReportableException::class);
        $this->reports->submit($review, $reporter, new SubmitReviewReportData(reason: ReviewReportReason::Spam));
    }

    public function test_hidden_review_cannot_receive_a_new_report(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $this->moderation->hide($review, $this->admin(), 'Routine check.');
        $reporter = $this->reporterUser();

        $this->expectException(ReviewNotReportableException::class);
        $this->reports->submit($review->fresh(), $reporter, new SubmitReviewReportData(reason: ReviewReportReason::Spam));
    }

    public function test_rejected_review_cannot_be_reported(): void
    {
        $this->enableReviews(['moderation_model' => 'pre_moderation']);
        $review = $this->submitPublicReview()->fresh();
        $this->moderation->reject($review, $this->admin(), 'Not suitable.');
        $reporter = $this->reporterUser();

        $this->expectException(ReviewNotReportableException::class);
        $this->reports->submit($review->fresh(), $reporter, new SubmitReviewReportData(reason: ReviewReportReason::Spam));
    }

    public function test_archived_review_cannot_be_reported(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $admin = $this->admin();
        $hidden = $this->moderation->hide($review, $admin, 'Pending cleanup.');
        $this->moderation->archive($hidden->fresh(), $admin, 'No longer relevant.');
        $reporter = $this->reporterUser();

        $this->expectException(ReviewNotReportableException::class);
        $this->reports->submit($review->fresh(), $reporter, new SubmitReviewReportData(reason: ReviewReportReason::Spam));
    }

    // ── 7. Invalid reason ─────────────────────────────────────────────────

    public function test_invalid_reason_is_rejected(): void
    {
        $this->assertNull(ReviewReportReason::tryFrom('not_a_real_reason'));
    }

    // ── 8–10. Sanitization & privacy ──────────────────────────────────────

    public function test_optional_explanation_is_sanitized(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $reporter = $this->reporterUser();

        $report = $this->reports->submit($review, $reporter, new SubmitReviewReportData(
            reason: ReviewReportReason::PersonalInformation,
            explanation: '<script>alert(1)</script>Contact me at leaky@example.com now.',
        ));

        $this->assertStringNotContainsString('<script>', (string) $report->explanation);
        $this->assertStringNotContainsString('leaky@example.com', (string) $report->explanation);
    }

    public function test_contact_information_is_not_stored(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $reporter = $this->reporterUser();

        $report = $this->reports->submit($review, $reporter, new SubmitReviewReportData(
            reason: ReviewReportReason::OffPlatformSolicitation,
            explanation: 'Call me on +1 (555) 222-3333 or email leaky@example.com.',
        ));

        $this->assertStringNotContainsString('555', (string) $report->explanation);
        $this->assertStringNotContainsString('@example.com', (string) $report->explanation);
    }

    public function test_raw_unsafe_explanation_is_not_logged(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $reporter = $this->reporterUser();

        $this->reports->submit($review, $reporter, new SubmitReviewReportData(
            reason: ReviewReportReason::PersonalInformation,
            explanation: 'Their phone is +1 (555) 999-8888.',
        ));

        $activity = Activity::query()
            ->where('log_name', 'reviews')
            ->where('event', 'review_reported')
            ->latest('id')
            ->firstOrFail();

        $raw = json_encode($activity->properties);
        $this->assertStringNotContainsString('555', (string) $raw);
        $this->assertSame(['phone_number'], $activity->properties->get('flags'));
    }

    // ── 11–13. Duplicate & multiple reports ────────────────────────────────

    public function test_duplicate_report_by_same_user_and_reason_is_prevented(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $reporter = $this->reporterUser();

        $this->reports->submit($review, $reporter, new SubmitReviewReportData(reason: ReviewReportReason::Spam));

        $this->expectException(DuplicateReviewReportException::class);
        $this->reports->submit($review->fresh(), $reporter, new SubmitReviewReportData(reason: ReviewReportReason::Spam));
    }

    public function test_different_users_may_report_the_same_review(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $reporterA = $this->reporterUser();
        $reporterB = $this->reporterUser();

        $reportA = $this->reports->submit($review, $reporterA, new SubmitReviewReportData(reason: ReviewReportReason::Spam));
        $reportB = $this->reports->submit($review->fresh(), $reporterB, new SubmitReviewReportData(reason: ReviewReportReason::Spam));

        $this->assertNotSame($reportA->id, $reportB->id);
        $this->assertSame(2, ReviewReport::query()->where('review_id', $review->id)->count());
    }

    public function test_same_user_may_use_a_different_valid_reason(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $reporter = $this->reporterUser();

        $this->reports->submit($review, $reporter, new SubmitReviewReportData(reason: ReviewReportReason::Spam));
        $second = $this->reports->submit($review->fresh(), $reporter, new SubmitReviewReportData(reason: ReviewReportReason::HateOrHarassment));

        $this->assertSame(ReviewReportStatus::Pending, $second->status);
        $this->assertSame(2, ReviewReport::query()->where('review_id', $review->id)->count());
    }

    // ── 14–15. Resolution authorization ───────────────────────────────────

    public function test_unauthorized_user_cannot_resolve_a_report(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $report = $this->reports->submit($review, $this->reporterUser(), new SubmitReviewReportData(reason: ReviewReportReason::Spam));
        $stranger = User::factory()->create(['status' => 'active']);

        $this->expectException(AuthorizationException::class);
        $this->reports->dismiss($report, $stranger, 'Not valid.');
    }

    public function test_instructor_cannot_resolve_reports_about_their_own_review(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $report = $this->reports->submit($review, $this->reporterUser(), new SubmitReviewReportData(reason: ReviewReportReason::Spam));
        $instructor = $review->instructor;
        $instructor->assignRole('manager'); // even with full staff permission...
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->expectException(AuthorizationException::class);
        $this->reports->dismiss($report, $instructor, 'Not valid.'); // ...they still cannot resolve their own review's report
    }

    // ── 16–18. Admin lifecycle ───────────────────────────────────────────

    public function test_admin_can_start_review(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $report = $this->reports->submit($review, $this->reporterUser(), new SubmitReviewReportData(reason: ReviewReportReason::Spam));
        $admin = $this->admin();

        $started = $this->reports->startReview($report, $admin);

        $this->assertSame(ReviewReportStatus::UnderReview, $started->status);
        $this->assertSame($admin->id, $started->reviewed_by);
        $this->assertNotNull($started->reviewed_at);
    }

    public function test_admin_can_dismiss_a_report_with_a_reason(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $report = $this->reports->submit($review, $this->reporterUser(), new SubmitReviewReportData(reason: ReviewReportReason::Spam));
        $admin = $this->admin();

        $dismissed = $this->reports->dismiss($report, $admin, 'Content is legitimate, not spam.');

        $this->assertSame(ReviewReportStatus::Dismissed, $dismissed->status);
        $this->assertSame('Content is legitimate, not spam.', $dismissed->resolution_reason);
    }

    public function test_dismiss_requires_a_resolution_reason(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $report = $this->reports->submit($review, $this->reporterUser(), new SubmitReviewReportData(reason: ReviewReportReason::Spam));

        $this->expectException(ReviewValidationException::class);
        $this->reports->dismiss($report, $this->admin(), '   ');
    }

    public function test_uphold_requires_a_resolution_reason(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $report = $this->reports->submit($review, $this->reporterUser(), new SubmitReviewReportData(reason: ReviewReportReason::Spam));

        $this->expectException(ReviewValidationException::class);
        $this->reports->uphold($report, $this->admin(), '');
    }

    public function test_uphold_rejects_an_invalid_action(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $report = $this->reports->submit($review, $this->reporterUser(), new SubmitReviewReportData(reason: ReviewReportReason::Spam));

        $this->expectException(ReviewValidationException::class);
        $this->reports->uphold($report, $this->admin(), 'Confirmed abusive.', ReviewReportResolutionAction::RestoreReview);
    }

    public function test_admin_can_uphold_and_hide_a_review(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $report = $this->reports->submit($review, $this->reporterUser(), new SubmitReviewReportData(reason: ReviewReportReason::AbusiveLanguage));
        $admin = $this->admin();

        $upheld = $this->reports->uphold($report, $admin, 'Confirmed abusive language.', ReviewReportResolutionAction::HideReview);

        $this->assertSame(ReviewReportStatus::Upheld, $upheld->status);
        $this->assertSame(ReviewReportResolutionAction::HideReview, $upheld->resolution_action);
        $this->assertSame(StudentReviewStatus::Hidden, $review->fresh()->status);
    }

    // ── 19. Delegates to the existing moderation service ──────────────────

    public function test_review_hiding_uses_the_existing_moderation_service(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $report = $this->reports->submit($review, $this->reporterUser(), new SubmitReviewReportData(reason: ReviewReportReason::AbusiveLanguage));
        $admin = $this->admin();

        $this->reports->uphold($report, $admin, 'Confirmed abusive language.', ReviewReportResolutionAction::HideReview);

        // ReviewModerationService::hide() is the one that writes this
        // specific audit event — proves the report resolution never
        // wrote lesson_reviews.status itself.
        $this->assertTrue(
            Activity::query()->where('log_name', 'reviews')->where('event', 'review_hidden')->exists()
        );
        $this->assertSame($admin->id, $review->fresh()->moderated_by);
    }

    // ── 20. Public display & rating aggregate integration ──────────────────

    public function test_hidden_review_is_removed_from_public_display_and_rating_aggregate(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $instructor = $review->instructor;
        $this->assertSame(1, $this->ratings->summaryFor($instructor->id)->reviewCount);

        $report = $this->reports->submit($review, $this->reporterUser(), new SubmitReviewReportData(reason: ReviewReportReason::AbusiveLanguage));
        $this->reports->uphold($report, $this->admin(), 'Confirmed.', ReviewReportResolutionAction::HideReview);

        $this->assertSame(0, $this->ratings->summaryFor($instructor->id)->reviewCount);
        $publicPage = $this->publicReviews->paginatedReviewsFor($instructor->fresh());
        $this->assertCount(0, $publicPage->items());
    }

    // ── 21. Dismissal leaves the review untouched ──────────────────────────

    public function test_dismissed_report_leaves_review_published(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $instructor = $review->instructor;
        $report = $this->reports->submit($review, $this->reporterUser(), new SubmitReviewReportData(reason: ReviewReportReason::Spam));

        $this->reports->dismiss($report, $this->admin(), 'Not spam.');

        $this->assertSame(StudentReviewStatus::Published, $review->fresh()->status);
        $this->assertSame(1, $this->ratings->summaryFor($instructor->id)->reviewCount);
    }

    // ── 22–23. Idempotency & concurrency ──────────────────────────────────

    public function test_conflicting_concurrent_resolutions_apply_once(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $report = $this->reports->submit($review, $this->reporterUser(), new SubmitReviewReportData(reason: ReviewReportReason::Spam));
        $admin = $this->admin();

        $copyA = ReviewReport::query()->findOrFail($report->id);
        $copyB = ReviewReport::query()->findOrFail($report->id);

        $dismissed = $this->reports->dismiss($copyA, $admin, 'Not valid.');
        $this->assertSame(ReviewReportStatus::Dismissed, $dismissed->status);

        $this->expectException(InvalidReviewReportTransitionException::class);
        $this->reports->uphold($copyB, $admin, 'Actually valid.'); // conflicts with the already-Dismissed row
    }

    public function test_repeated_identical_resolution_is_idempotent(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $report = $this->reports->submit($review, $this->reporterUser(), new SubmitReviewReportData(reason: ReviewReportReason::Spam));
        $admin = $this->admin();

        $first = $this->reports->dismiss($report, $admin, 'Not valid.');
        $second = $this->reports->dismiss($first->fresh(), $admin, 'A different reason text this time.');

        $this->assertSame(ReviewReportStatus::Dismissed, $second->status);
        $this->assertSame($first->version, $second->version);
        // The second call's differing reason text never overwrote the first's.
        $this->assertSame('Not valid.', $second->resolution_reason);
    }

    public function test_remaining_pending_reports_are_marked_duplicate(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $reportA = $this->reports->submit($review, $this->reporterUser(), new SubmitReviewReportData(reason: ReviewReportReason::AbusiveLanguage));
        $reportB = $this->reports->submit($review->fresh(), $this->reporterUser(), new SubmitReviewReportData(reason: ReviewReportReason::HateOrHarassment));
        $admin = $this->admin();

        $this->reports->uphold($reportA, $admin, 'Confirmed.', ReviewReportResolutionAction::HideReview);
        $resolvedCount = $this->reports->markRemainingPendingAsDuplicate($review->fresh(), $admin, 'Same underlying review already resolved.');

        $this->assertSame(1, $resolvedCount);
        $this->assertSame(ReviewReportStatus::Duplicate, $reportB->fresh()->status);
        $this->assertSame(ReviewReportStatus::Upheld, $reportA->fresh()->status); // untouched by the bulk resolution
    }

    // ── 24. Never physically deleted ────────────────────────────────────

    public function test_review_and_report_records_are_never_physically_deleted(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $report = $this->reports->submit($review, $this->reporterUser(), new SubmitReviewReportData(reason: ReviewReportReason::AbusiveLanguage));
        $admin = $this->admin();

        $this->reports->uphold($report, $admin, 'Confirmed.', ReviewReportResolutionAction::HideReview);

        $this->assertDatabaseHas('lesson_reviews', ['id' => $review->id]);
        $this->assertDatabaseHas('review_reports', ['id' => $report->id]);
    }

    // ── 25. Reporter privacy ─────────────────────────────────────────────

    public function test_reporter_identity_is_absent_from_public_dtos_and_profile_html(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $instructor = $review->instructor;
        $reporter = $this->reporterUser(['first_name' => 'Priyanka']);

        $this->reports->submit($review, $reporter, new SubmitReviewReportData(
            reason: ReviewReportReason::AbusiveLanguage,
            explanation: 'This is inappropriate.',
        ));

        $page = $this->publicReviews->paginatedReviewsFor($instructor->fresh());
        $publicReview = $page->items()[0];
        $fields = array_keys(get_object_vars($publicReview));
        foreach (['reporterId', 'reporter_id', 'reportCount', 'explanation'] as $forbidden) {
            $this->assertNotContains($forbidden, $fields);
        }

        $response = $this->get(route('instructors.show', $instructor));
        $response->assertDontSee('This is inappropriate.');
        $response->assertDontSee('Priyanka');
    }

    // ── 26–27. No unrelated side effects ──────────────────────────────────

    public function test_no_notification_or_quality_alert_is_created(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $report = $this->reports->submit($review, $this->reporterUser(), new SubmitReviewReportData(reason: ReviewReportReason::AbusiveLanguage));
        $admin = $this->admin();

        // Phase 17S later attaches ReviewHiddenNotification to the
        // student when a report-driven hide takes effect — the one
        // expected exception; the point of this test is that report
        // resolution creates no *quality alert*.
        Notification::fake();
        $this->reports->uphold($report, $admin, 'Confirmed.', ReviewReportResolutionAction::HideReview);

        Notification::assertSentTo($review->student, ReviewHiddenNotification::class);
        $this->assertFalse(Schema::hasTable('review_quality_alerts'));
    }

    public function test_no_financial_booking_lesson_or_earning_record_changes(): void
    {
        $lesson = $this->paidLesson();
        $review = $this->submitPublicReview($lesson)->fresh();
        $originalOutcome = $lesson->refresh()->outcome;
        $report = $this->reports->submit($review, $this->reporterUser(), new SubmitReviewReportData(reason: ReviewReportReason::Spam));

        $this->reports->dismiss($report, $this->admin(), 'Not spam.');

        $this->assertSame($originalOutcome, $lesson->refresh()->outcome);
        $this->assertSame(0, DB::table('wallets')->count());
        $this->assertSame(0, DB::table('wallet_ledger_entries')->count());
        $this->assertSame(0, DB::table('instructor_earnings')->count());
    }

    // ── 28. Phase 17J–17L regression ──────────────────────────────────────

    public function test_phase_17j_to_17l_regression_unaffected(): void
    {
        $review = $this->submitPublicReview(content: 'DM me on @sketchy_handle for more info.')->fresh();
        $this->assertSame(StudentReviewStatus::Flagged, $review->status);

        $approved = $this->moderation->approve($review, $this->admin(), 'Reviewed manually — fine.');

        $this->assertSame(StudentReviewStatus::Published, $approved->status);
        $this->assertSame(1, $this->ratings->summaryFor($review->instructor_id)->reviewCount);

        $response = $this->get(route('instructors.show', $review->instructor));
        $response->assertOk();
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function paidLesson(?User $instructor = null, ?User $student = null): Lesson
    {
        $endsAt = now()->subHours(2)->startOfHour();

        $booking = Booking::factory()->confirmed()->create([
            'booking_type_id' => BookingType::factory()->paid(),
            'host_id' => $instructor?->id ?? $this->instructorUser()->id,
            'attendee_id' => $student?->id ?? User::factory(),
            'starts_at' => $endsAt->copy()->subMinutes(60),
            'ends_at' => $endsAt,
            'payment_status' => BookingPaymentStatus::Paid,
            'price' => '499.00',
            'currency' => 'INR',
        ]);

        return $this->lifecycle->createFromBooking($booking);
    }

    private function demoLesson(?User $instructor = null, ?User $student = null): Lesson
    {
        $endsAt = now()->subHours(2)->startOfHour();

        $booking = Booking::factory()->confirmed()->create([
            'host_id' => $instructor?->id ?? $this->instructorUser()->id,
            'attendee_id' => $student?->id ?? User::factory(),
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

    private function submitPublicReview(?Lesson $lesson = null, string $content = 'A genuinely helpful and well-structured lesson overall.'): LessonReview
    {
        $eligibility = $this->openEligibility($lesson ?? $this->paidLesson());

        $result = $this->submissions->submit($eligibility, $eligibility->student, new SubmitStudentReviewData(
            overallRating: 5,
            content: $content,
        ));

        return $result->review;
    }

    private function submitPrivateFeedback(string $content = 'Helpful trial session, thanks for the demo lesson.'): LessonReview
    {
        $this->enableReviews(['demo_review_policy' => 'private_only']);
        $eligibility = $this->openEligibility($this->demoLesson());

        $result = $this->submissions->submit($eligibility, $eligibility->student, new SubmitStudentReviewData(
            overallRating: 4,
            content: $content,
        ));

        return $result->review;
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

        foreach ($overrides as $key => $value) {
            $settings->{$key} = $value;
        }

        $settings->save();
    }

    private function instructorUser(): User
    {
        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->profile->update(['profile_visibility' => 'public', 'instructor_status' => InstructorStatus::Approved]);
        $instructor->assignRole('instructor');

        return $instructor;
    }

    /** @param array<string, mixed> $overrides */
    private function reporterUser(array $overrides = []): User
    {
        $reporter = User::factory()->create(array_merge(['status' => 'active'], $overrides));
        $reporter->assignRole('student');

        return $reporter;
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('manager');

        return $admin;
    }
}
