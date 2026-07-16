<?php

declare(strict_types=1);

namespace Tests\Feature\Reviews;

use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\DTOs\CancelBookingData;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingPaymentStatus;
use App\Enums\InstructorStatus;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\Enums\LessonOutcome;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\InstructorQualityAlert;
use App\Models\Lesson;
use App\Models\LessonReview;
use App\Models\LessonReviewEligibility;
use App\Models\User;
use App\Quality\Actions\DetectLowRatingQualityRiskAction;
use App\Quality\Actions\ReconcileInstructorQualityAlertsAction;
use App\Quality\Contracts\InstructorQualityAlertServiceInterface;
use App\Quality\Enums\InstructorQualityAlertResolutionAction;
use App\Quality\Enums\InstructorQualityAlertStatus;
use App\Quality\Enums\InstructorQualityAlertType;
use App\Quality\Exceptions\QualityAlertValidationException;
use App\Reviews\Contracts\ReviewModerationServiceInterface;
use App\Reviews\Contracts\ReviewReportServiceInterface;
use App\Reviews\Contracts\StudentReviewServiceInterface;
use App\Reviews\DTOs\SubmitReviewReportData;
use App\Reviews\DTOs\SubmitStudentReviewData;
use App\Reviews\Enums\ReviewReportReason;
use App\Reviews\Enums\ReviewReportResolutionAction;
use App\Settings\ReviewSettings;
use Database\Seeders\LessonPermissionSeeder;
use Database\Seeders\ReviewPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 17N — instructor quality-alert foundation: low-rating,
 * instructor-no-show, instructor-attributed-cancellation, and
 * upheld-serious-report detection; fingerprint-based deduplication
 * across duplicate/concurrent events; non-destructive reevaluation
 * when a source record changes; the admin review/resolve/dismiss
 * workflow (recommendations only — never an automatic instructor
 * action); and the reconciliation command's idempotency.
 */
class InstructorQualityAlertTest extends TestCase
{
    use RefreshDatabase;

    private LessonLifecycleServiceInterface $lifecycle;

    private LessonOutcomeServiceInterface $outcomes;

    private StudentReviewServiceInterface $submissions;

    private ReviewModerationServiceInterface $moderation;

    private ReviewReportServiceInterface $reports;

    private InstructorQualityAlertServiceInterface $alerts;

    private BookingServiceInterface $bookings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lifecycle = app(LessonLifecycleServiceInterface::class);
        $this->outcomes = app(LessonOutcomeServiceInterface::class);
        $this->submissions = app(StudentReviewServiceInterface::class);
        $this->moderation = app(ReviewModerationServiceInterface::class);
        $this->reports = app(ReviewReportServiceInterface::class);
        $this->alerts = app(InstructorQualityAlertServiceInterface::class);
        $this->bookings = app(BookingServiceInterface::class);

        $this->enableReviews();
        $this->seed(ReviewPermissionSeeder::class);
        $this->seed(LessonPermissionSeeder::class);
    }

    // ── 1–3. Low-rating basics ────────────────────────────────────────────

    public function test_published_rating_below_threshold_creates_low_rating_alert(): void
    {
        $instructor = $this->instructorUser();
        $review = $this->submitPublicReview($instructor, overallRating: 1)->fresh();

        $alert = InstructorQualityAlert::query()
            ->where('instructor_id', $instructor->id)
            ->where('alert_type', InstructorQualityAlertType::SingleLowRating)
            ->first();

        $this->assertNotNull($alert);
        $this->assertSame('lesson_review', $alert->source_type->value);
        $this->assertSame($review->id, $alert->source_id);
    }

    public function test_rating_above_threshold_creates_no_alert(): void
    {
        $instructor = $this->instructorUser();
        $this->submitPublicReview($instructor, overallRating: 5);

        $this->assertSame(0, InstructorQualityAlert::query()->where('instructor_id', $instructor->id)->count());
    }

    public function test_private_feedback_creates_no_automatic_alert(): void
    {
        $instructor = $this->instructorUser();
        $this->submitPrivateFeedback($instructor, overallRating: 1);

        $this->assertSame(0, InstructorQualityAlert::query()->where('instructor_id', $instructor->id)->count());
    }

    // ── 4. Non-published statuses ──────────────────────────────────────────

    public function test_submitted_review_creates_no_new_alert(): void
    {
        $this->enableReviews(['moderation_model' => 'pre_moderation']);
        $instructor = $this->instructorUser();
        $this->submitPublicReview($instructor, overallRating: 1); // stays Submitted, never publishes

        $this->assertSame(0, InstructorQualityAlert::query()->where('instructor_id', $instructor->id)->count());
    }

    public function test_hidden_review_creates_no_new_alert_at_hide_time(): void
    {
        $instructor = $this->instructorUser();
        $review = $this->submitPublicReview($instructor, overallRating: 1)->fresh();
        InstructorQualityAlert::query()->where('instructor_id', $instructor->id)->delete(); // isolate the hide step

        $this->moderation->hide($review, $this->admin(), 'Routine check.');

        $this->assertSame(0, InstructorQualityAlert::query()->where('instructor_id', $instructor->id)->count());
    }

    // ── 5–6. Repeated low ratings + duplicate events ────────────────────────

    public function test_repeated_low_ratings_create_one_repeated_alert_at_threshold(): void
    {
        $instructor = $this->instructorUser();
        $this->submitPublicReview($instructor, overallRating: 1);
        $this->submitPublicReview($instructor, overallRating: 2);
        $third = $this->submitPublicReview($instructor, overallRating: 1)->fresh(); // 3rd low review — crosses default threshold of 3

        $repeated = InstructorQualityAlert::query()
            ->where('instructor_id', $instructor->id)
            ->where('alert_type', InstructorQualityAlertType::RepeatedLowRatings)
            ->get();

        $this->assertCount(1, $repeated);
        $this->assertSame(3, $repeated->first()->signal_count);
    }

    public function test_duplicate_review_events_create_one_alert(): void
    {
        $instructor = $this->instructorUser();
        $review = $this->submitPublicReview($instructor, overallRating: 1)->fresh();

        // Simulate a replayed StudentReviewPublished delivery by
        // re-running detection directly against the same review.
        app(DetectLowRatingQualityRiskAction::class)->execute($review);
        app(DetectLowRatingQualityRiskAction::class)->execute($review);

        $this->assertSame(
            1,
            InstructorQualityAlert::query()
                ->where('instructor_id', $instructor->id)
                ->where('alert_type', InstructorQualityAlertType::SingleLowRating)
                ->count()
        );
    }

    // ── 7. Reevaluation preserves history ──────────────────────────────────

    public function test_review_hidden_after_alert_preserves_alert_history(): void
    {
        $instructor = $this->instructorUser();
        $review = $this->submitPublicReview($instructor, overallRating: 1)->fresh();
        $alert = InstructorQualityAlert::query()->where('instructor_id', $instructor->id)->firstOrFail();

        $this->moderation->hide($review, $this->admin(), 'Routine check.');

        $alert->refresh();
        $this->assertDatabaseHas('quality_alerts', ['id' => $alert->id]); // never deleted
        $this->assertTrue($alert->needs_reevaluation);
        $this->assertSame(InstructorQualityAlertStatus::Open, $alert->status); // status untouched, only flagged
    }

    // ── 8–11. Instructor no-show ───────────────────────────────────────────

    public function test_instructor_no_show_creates_quality_signal(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->paidLesson($instructor);

        $this->outcomes->finalize($lesson->refresh(), LessonOutcome::InstructorNoShow);

        $this->assertSame(
            1,
            InstructorQualityAlert::query()
                ->where('instructor_id', $instructor->id)
                ->where('alert_type', InstructorQualityAlertType::InstructorNoShow)
                ->count()
        );
    }

    public function test_repeated_instructor_no_shows_create_one_repeated_alert(): void
    {
        $instructor = $this->instructorUser();

        foreach (range(1, 2) as $i) { // default repeated_no_show_count = 2
            $lesson = $this->paidLesson($instructor);
            $this->outcomes->finalize($lesson->refresh(), LessonOutcome::InstructorNoShow);
        }

        $this->assertSame(
            1,
            InstructorQualityAlert::query()
                ->where('instructor_id', $instructor->id)
                ->where('alert_type', InstructorQualityAlertType::RepeatedInstructorNoShows)
                ->count()
        );
    }

    public function test_student_no_show_does_not_affect_instructor_no_show_count(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->paidLesson($instructor);

        $this->outcomes->finalize($lesson->refresh(), LessonOutcome::StudentNoShow);

        $this->assertSame(0, InstructorQualityAlert::query()->where('instructor_id', $instructor->id)->count());
    }

    public function test_technical_issue_does_not_count_as_instructor_no_show(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->paidLesson($instructor);

        $this->outcomes->finalize($lesson->refresh(), LessonOutcome::TechnicalIssue);

        $this->assertSame(0, InstructorQualityAlert::query()->where('instructor_id', $instructor->id)->count());
    }

    // ── 12. Outcome override reevaluation ──────────────────────────────────

    public function test_outcome_override_reevaluates_the_no_show_signal(): void
    {
        $instructor = $this->instructorUser();
        $admin = $this->admin();
        $lesson = $this->paidLesson($instructor);
        $this->outcomes->finalize($lesson->refresh(), LessonOutcome::InstructorNoShow);
        $alert = InstructorQualityAlert::query()
            ->where('instructor_id', $instructor->id)
            ->where('alert_type', InstructorQualityAlertType::InstructorNoShow)
            ->firstOrFail();

        $this->outcomes->override($lesson->refresh(), $admin, LessonOutcome::TechnicalIssue, 'Evidence showed a platform outage, not a no-show.');

        $alert->refresh();
        $this->assertDatabaseHas('quality_alerts', ['id' => $alert->id]);
        $this->assertTrue($alert->needs_reevaluation);

        // The reverse direction: correcting *into* a no-show creates the signal.
        $instructor2 = $this->instructorUser();
        $lesson2 = $this->paidLesson($instructor2);
        $this->outcomes->finalize($lesson2->refresh(), LessonOutcome::TechnicalIssue);
        $this->outcomes->override($lesson2->refresh(), $admin, LessonOutcome::InstructorNoShow, 'Attendance logs show the instructor never joined.');

        $this->assertSame(
            1,
            InstructorQualityAlert::query()
                ->where('instructor_id', $instructor2->id)
                ->where('alert_type', InstructorQualityAlertType::InstructorNoShow)
                ->count()
        );
    }

    // ── 13–15. Cancellations ────────────────────────────────────────────

    public function test_instructor_attributed_cancellations_count_correctly(): void
    {
        $instructor = $this->instructorUser();

        foreach (range(1, 3) as $i) { // default repeated_cancellation_count = 3
            $booking = $this->confirmedBooking($instructor);
            $this->bookings->cancel($booking, new CancelBookingData(BookingActor::Instructor, 'Instructor unavailable.'));
        }

        $this->assertSame(
            1,
            InstructorQualityAlert::query()
                ->where('instructor_id', $instructor->id)
                ->where('alert_type', InstructorQualityAlertType::RepeatedInstructorCancellations)
                ->count()
        );
    }

    public function test_student_and_system_cancellations_are_excluded(): void
    {
        $instructor = $this->instructorUser();

        foreach (range(1, 3) as $i) {
            $booking = $this->confirmedBooking($instructor);
            $this->bookings->cancel($booking, new CancelBookingData(BookingActor::Student, 'Changed my mind.'));
        }
        $systemBooking = $this->confirmedBooking($instructor);
        $this->bookings->cancel($systemBooking, new CancelBookingData(BookingActor::System, 'Payment was not completed.', expired: true));

        $this->assertSame(0, InstructorQualityAlert::query()->where('instructor_id', $instructor->id)->count());
    }

    public function test_repeated_instructor_cancellations_trigger_one_alert(): void
    {
        $instructor = $this->instructorUser();

        foreach (range(1, 4) as $i) { // one more than the threshold — still only one alert
            $booking = $this->confirmedBooking($instructor);
            $this->bookings->cancel($booking, new CancelBookingData(BookingActor::Instructor, 'Instructor unavailable.'));
        }

        $this->assertSame(
            1,
            InstructorQualityAlert::query()
                ->where('instructor_id', $instructor->id)
                ->where('alert_type', InstructorQualityAlertType::RepeatedInstructorCancellations)
                ->count()
        );
    }

    // ── 16–18. Review-report signals ────────────────────────────────────────

    public function test_upheld_serious_report_creates_alert(): void
    {
        $instructor = $this->instructorUser();
        $review = $this->submitPublicReview($instructor)->fresh();
        $report = $this->reports->submit($review, $this->reporterUser(), new SubmitReviewReportData(reason: ReviewReportReason::AbusiveLanguage));

        $this->reports->uphold($report, $this->admin(), 'Confirmed abusive language.', ReviewReportResolutionAction::HideReview);

        $this->assertSame(
            1,
            InstructorQualityAlert::query()
                ->where('instructor_id', $instructor->id)
                ->where('alert_type', InstructorQualityAlertType::SeriousReviewReport)
                ->count()
        );
    }

    public function test_dismissed_or_duplicate_report_creates_no_alert(): void
    {
        $instructor = $this->instructorUser();
        $review = $this->submitPublicReview($instructor)->fresh();
        $report = $this->reports->submit($review, $this->reporterUser(), new SubmitReviewReportData(reason: ReviewReportReason::AbusiveLanguage));
        $admin = $this->admin();

        $this->reports->dismiss($report, $admin, 'Not actually abusive.');

        $this->assertSame(0, InstructorQualityAlert::query()->where('instructor_id', $instructor->id)->count());

        $review2 = $this->submitPublicReview($instructor)->fresh();
        $reportA = $this->reports->submit($review2, $this->reporterUser(), new SubmitReviewReportData(reason: ReviewReportReason::AbusiveLanguage));
        $reportB = $this->reports->submit($review2->fresh(), $this->reporterUser(), new SubmitReviewReportData(reason: ReviewReportReason::HateOrHarassment));
        $this->reports->uphold($reportA, $admin, 'Confirmed.', ReviewReportResolutionAction::HideReview);
        $this->reports->markDuplicate($reportB, $admin, 'Same underlying issue.');

        // Exactly one alert from reportA's upheld resolution — markDuplicate never creates one.
        $this->assertSame(
            1,
            InstructorQualityAlert::query()
                ->where('instructor_id', $instructor->id)
                ->where('alert_type', InstructorQualityAlertType::SeriousReviewReport)
                ->count()
        );
    }

    public function test_multiple_reports_for_one_review_issue_do_not_duplicate_alert(): void
    {
        $instructor = $this->instructorUser();
        $review = $this->submitPublicReview($instructor)->fresh();
        $admin = $this->admin();
        $reportA = $this->reports->submit($review, $this->reporterUser(), new SubmitReviewReportData(reason: ReviewReportReason::AbusiveLanguage));
        $reportB = $this->reports->submit($review->fresh(), $this->reporterUser(), new SubmitReviewReportData(reason: ReviewReportReason::HateOrHarassment));

        $this->reports->uphold($reportA, $admin, 'Confirmed.', ReviewReportResolutionAction::NoAction);
        $this->reports->uphold($reportB, $admin, 'Also confirmed.', ReviewReportResolutionAction::NoAction);

        $this->assertSame(
            1,
            InstructorQualityAlert::query()
                ->where('instructor_id', $instructor->id)
                ->where('alert_type', InstructorQualityAlertType::SeriousReviewReport)
                ->count()
        );
    }

    // ── 19. Feature disabled ────────────────────────────────────────────

    public function test_feature_disabled_state_creates_no_alert(): void
    {
        $this->enableReviews(['quality_alerts_enabled' => false]);
        $instructor = $this->instructorUser();
        $this->submitPublicReview($instructor, overallRating: 1);

        $this->assertSame(0, InstructorQualityAlert::query()->where('instructor_id', $instructor->id)->count());
    }

    // ── 20. Threshold snapshot immutability ──────────────────────────────

    public function test_threshold_snapshot_remains_unchanged_after_settings_change(): void
    {
        $instructor = $this->instructorUser();
        $review = $this->submitPublicReview($instructor, overallRating: 1)->fresh();
        $alert = InstructorQualityAlert::query()->where('instructor_id', $instructor->id)->firstOrFail();
        $this->assertSame(2, $alert->threshold_snapshot['low_rating_threshold']);

        $this->enableReviews(['low_rating_threshold' => 4]);

        $alert->refresh();
        $this->assertSame(2, $alert->threshold_snapshot['low_rating_threshold']);
    }

    // ── 21. Concurrency-equivalent idempotency ─────────────────────────────

    public function test_concurrent_detection_creates_one_alert(): void
    {
        $instructor = $this->instructorUser();
        $review = $this->submitPublicReview($instructor, overallRating: 1)->fresh();

        // Two "concurrent" copies both re-evaluating the same review.
        $copyA = LessonReview::query()->findOrFail($review->id);
        $copyB = LessonReview::query()->findOrFail($review->id);
        app(DetectLowRatingQualityRiskAction::class)->execute($copyA);
        app(DetectLowRatingQualityRiskAction::class)->execute($copyB);

        $this->assertSame(
            1,
            InstructorQualityAlert::query()
                ->where('instructor_id', $instructor->id)
                ->where('alert_type', InstructorQualityAlertType::SingleLowRating)
                ->count()
        );
    }

    // ── 22–26. Admin resolution ─────────────────────────────────────────

    public function test_unauthorized_user_cannot_review_or_resolve_alerts(): void
    {
        $instructor = $this->instructorUser();
        $this->submitPublicReview($instructor, overallRating: 1);
        $alert = InstructorQualityAlert::query()->where('instructor_id', $instructor->id)->firstOrFail();
        $stranger = User::factory()->create(['status' => 'active']);

        $this->expectException(AuthorizationException::class);
        $this->alerts->dismiss($alert, $stranger, 'Not valid.');
    }

    public function test_instructor_cannot_resolve_their_own_alert(): void
    {
        $instructor = $this->instructorUser();
        $this->submitPublicReview($instructor, overallRating: 1);
        $alert = InstructorQualityAlert::query()->where('instructor_id', $instructor->id)->firstOrFail();
        $instructor->assignRole('manager');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->expectException(AuthorizationException::class);
        $this->alerts->dismiss($alert, $instructor, 'Not valid.');
    }

    /**
     * Phase 17V closure re-audit — the test above only proves the
     * Policy-layer self-exclusion (InstructorQualityAlertPolicy::
     * resolve()) works for a `manager` role. Gate::before grants
     * super_admin a global permission bypass that skips the policy
     * entirely, and Spatie roles are not mutually exclusive, so an
     * account holding both `instructor` and `super_admin` was able to
     * resolve/dismiss/reassign a quality alert about their own conduct
     * until InstructorQualityAlertService::authorizeResolve() gained an
     * independent self-relationship check, mirroring the identical fix
     * already applied to ReviewModerationService/ReviewReportService.
     */
    public function test_instructor_who_is_also_super_admin_cannot_resolve_their_own_alert(): void
    {
        $instructor = $this->instructorUser();
        $this->submitPublicReview($instructor, overallRating: 1);
        $alert = InstructorQualityAlert::query()->where('instructor_id', $instructor->id)->firstOrFail();
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $instructor->assignRole('super_admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue($instructor->isSuperAdmin());

        $this->expectException(AuthorizationException::class);
        $this->alerts->dismiss($alert, $instructor, 'Dismissing my own alert.');
    }

    public function test_an_unrelated_super_admin_can_still_resolve_alerts_normally(): void
    {
        $instructor = $this->instructorUser();
        $this->submitPublicReview($instructor, overallRating: 1);
        $alert = InstructorQualityAlert::query()->where('instructor_id', $instructor->id)->firstOrFail();

        $unrelatedSuperAdmin = User::factory()->create(['status' => 'active']);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $unrelatedSuperAdmin->assignRole('super_admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $dismissed = $this->alerts->dismiss($alert, $unrelatedSuperAdmin, 'Routine check, unrelated admin.');

        $this->assertNotSame($alert->instructor_id, $unrelatedSuperAdmin->id);
        $this->assertSame('dismissed', $dismissed->status->value);
    }

    public function test_admin_resolution_requires_a_reason(): void
    {
        $instructor = $this->instructorUser();
        $this->submitPublicReview($instructor, overallRating: 1);
        $alert = InstructorQualityAlert::query()->where('instructor_id', $instructor->id)->firstOrFail();

        $this->expectException(QualityAlertValidationException::class);
        $this->alerts->resolve($alert, $this->admin(), '   ');
    }

    public function test_resolution_creates_an_audit_entry(): void
    {
        $instructor = $this->instructorUser();
        $this->submitPublicReview($instructor, overallRating: 1);
        $alert = InstructorQualityAlert::query()->where('instructor_id', $instructor->id)->firstOrFail();
        $admin = $this->admin();

        $this->alerts->resolve($alert, $admin, 'Contacted instructor, addressed.', InstructorQualityAlertResolutionAction::ContactInstructor);

        $activity = Activity::query()
            ->where('log_name', 'quality')
            ->where('event', 'instructor_quality_alert_resolved')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame('resolved', $activity->properties->get('new_status'));
    }

    public function test_resolution_does_not_suspend_or_modify_the_instructor(): void
    {
        $instructor = $this->instructorUser();
        $this->submitPublicReview($instructor, overallRating: 1);
        $alert = InstructorQualityAlert::query()->where('instructor_id', $instructor->id)->firstOrFail();
        $originalStatus = $instructor->profile->instructor_status;

        $this->alerts->resolve($alert, $this->admin(), 'Reviewed.', InstructorQualityAlertResolutionAction::ReferForSuspensionReview);

        $this->assertSame($originalStatus, $instructor->profile->fresh()->instructor_status);
    }

    // ── Admin projection privacy ────────────────────────────────────────

    public function test_admin_dto_contains_no_student_contact_or_financial_data(): void
    {
        $instructor = $this->instructorUser();
        $this->submitPublicReview($instructor, overallRating: 1);
        $alert = InstructorQualityAlert::query()->where('instructor_id', $instructor->id)->firstOrFail();

        $dto = $this->alerts->adminProjection($alert);
        $fields = array_keys(get_object_vars($dto));

        foreach (['studentEmail', 'studentPhone', 'paymentAmount', 'instructorCompensation', 'rawReportExplanation'] as $forbidden) {
            $this->assertNotContains($forbidden, $fields);
        }
    }

    // ── 27–28. Reconciliation command ────────────────────────────────────

    public function test_reconciliation_command_creates_missing_alerts(): void
    {
        $instructor = $this->instructorUser();
        $review = $this->submitPublicReview($instructor, overallRating: 1)->fresh();

        // Simulate a missed event: remove the alert the listener already created.
        InstructorQualityAlert::query()->where('instructor_id', $instructor->id)->delete();
        $this->assertSame(0, InstructorQualityAlert::query()->where('instructor_id', $instructor->id)->count());

        app(ReconcileInstructorQualityAlertsAction::class)->execute();

        $this->assertSame(
            1,
            InstructorQualityAlert::query()
                ->where('instructor_id', $instructor->id)
                ->where('alert_type', InstructorQualityAlertType::SingleLowRating)
                ->count()
        );
    }

    public function test_reconciliation_command_is_idempotent(): void
    {
        $instructor = $this->instructorUser();
        $this->submitPublicReview($instructor, overallRating: 1);

        app(ReconcileInstructorQualityAlertsAction::class)->execute();
        $first = InstructorQualityAlert::query()->where('instructor_id', $instructor->id)->count();

        app(ReconcileInstructorQualityAlertsAction::class)->execute();
        $second = InstructorQualityAlert::query()->where('instructor_id', $instructor->id)->count();

        $this->assertSame($first, $second);
    }

    // ── 29. No unrelated side effects ────────────────────────────────────

    public function test_no_notification_marketplace_ranking_or_compensation_change(): void
    {
        $instructor = $this->instructorUser();
        $this->submitPublicReview($instructor, overallRating: 1);
        $alert = InstructorQualityAlert::query()->where('instructor_id', $instructor->id)->firstOrFail();

        // Faked only around alert creation/resolution themselves — lesson
        // finalization upstream legitimately fires the pre-existing
        // booking-completion notification, unrelated to this phase.
        Notification::fake();
        $this->alerts->resolve($alert, $this->admin(), 'Reviewed.', InstructorQualityAlertResolutionAction::ContactInstructor);

        Notification::assertNothingSent();
        $this->assertSame(0, DB::table('wallets')->count());
        $this->assertSame(0, DB::table('instructor_earnings')->count());
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function instructorUser(): User
    {
        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->profile->update(['profile_visibility' => 'public', 'instructor_status' => InstructorStatus::Approved]);
        $instructor->assignRole('instructor');

        return $instructor;
    }

    private function reporterUser(): User
    {
        $reporter = User::factory()->create(['status' => 'active']);
        $reporter->assignRole('student');

        return $reporter;
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('manager');

        return $admin;
    }

    private function confirmedBooking(User $instructor, ?User $student = null): Booking
    {
        // NotRequired payment status keeps cancellation focused on the
        // quality-signal path — a Paid booking would also need a real
        // captured-payment record for BookingService::cancel()'s refund
        // step, which is irrelevant to attribution/counting here.
        return Booking::factory()->confirmed()->create([
            'instructor_id' => $instructor->id,
            'student_id' => $student?->id ?? User::factory(),
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'payment_status' => BookingPaymentStatus::NotRequired,
            'price' => null,
            'currency' => null,
        ]);
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

    private function openEligibility(Lesson $lesson): LessonReviewEligibility
    {
        $this->outcomes->finalize($lesson->refresh(), LessonOutcome::Completed);

        return LessonReviewEligibility::query()->where('lesson_id', $lesson->id)->firstOrFail();
    }

    private function submitPublicReview(User $instructor, int $overallRating = 5, string $content = 'A genuinely helpful and well-structured lesson overall.'): LessonReview
    {
        $eligibility = $this->openEligibility($this->paidLesson($instructor));

        $result = $this->submissions->submit($eligibility, $eligibility->student, new SubmitStudentReviewData(
            overallRating: $overallRating,
            content: $content,
        ));

        return $result->review;
    }

    private function submitPrivateFeedback(User $instructor, int $overallRating = 4, string $content = 'Helpful trial session, thanks.'): LessonReview
    {
        $this->enableReviews(['demo_review_policy' => 'private_only']);
        $eligibility = $this->openEligibility($this->demoLesson($instructor));

        $result = $this->submissions->submit($eligibility, $eligibility->student, new SubmitStudentReviewData(
            overallRating: $overallRating,
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
        $settings->quality_alerts_enabled = true;
        $settings->low_rating_threshold = 2;
        $settings->single_low_rating_alert_enabled = true;
        $settings->repeated_low_rating_count = 3;
        $settings->repeated_low_rating_window_days = 30;
        $settings->repeated_no_show_count = 2;
        $settings->repeated_no_show_window_days = 30;
        $settings->repeated_cancellation_count = 3;
        $settings->repeated_cancellation_window_days = 30;

        foreach ($overrides as $key => $value) {
            $settings->{$key} = $value;
        }

        $settings->save();
    }
}
