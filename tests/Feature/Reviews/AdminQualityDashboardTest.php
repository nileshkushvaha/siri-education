<?php

declare(strict_types=1);

namespace Tests\Feature\Reviews;

use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\DTOs\CancelBookingData;
use App\Booking\Enums\BookingActor;
use App\Booking\Enums\BookingPaymentStatus;
use App\Enums\InstructorStatus;
use App\Filament\Pages\ReviewsQualityDashboard;
use App\Filament\Widgets\Quality\AlertQueueWidget;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\Enums\LessonOutcome;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\InstructorQualityAlert;
use App\Models\Lesson;
use App\Models\LessonReview;
use App\Models\LessonReviewEligibility;
use App\Models\User;
use App\Quality\Contracts\AdminQualityDashboardServiceInterface;
use App\Quality\Contracts\InstructorQualityAlertServiceInterface;
use App\Quality\DTOs\AlertQueueFilters;
use App\Quality\DTOs\ModerationQueueFilters;
use App\Quality\DTOs\ReportQueueFilters;
use App\Quality\DTOs\TrendDateRange;
use App\Quality\Enums\InstructorQualityAlertResolutionAction;
use App\Quality\Enums\InstructorQualityAlertStatus;
use App\Reviews\Contracts\ReviewModerationServiceInterface;
use App\Reviews\Contracts\ReviewReportServiceInterface;
use App\Reviews\Contracts\StudentReviewServiceInterface;
use App\Reviews\DTOs\SubmitReviewReportData;
use App\Reviews\DTOs\SubmitStudentReviewData;
use App\Reviews\Enums\ReviewReportReason;
use App\Reviews\Enums\ReviewReportResolutionAction;
use App\Reviews\Enums\StudentReviewStatus;
use App\Settings\ReviewSettings;
use Carbon\CarbonImmutable;
use Database\Seeders\LessonPermissionSeeder;
use Database\Seeders\ReviewPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 17O — admin review & quality-assurance dashboard: read-only
 * aggregation over Phase 17H–17N's authoritative tables, permission-
 * gated access, correct summary/queue/rating-health/trend
 * calculations, UTC-safe bounded date ranges, action delegation to
 * the existing moderation/report/alert services, and the guarantee
 * that reading the dashboard never mutates anything.
 */
class AdminQualityDashboardTest extends TestCase
{
    use RefreshDatabase;

    private LessonLifecycleServiceInterface $lifecycle;

    private LessonOutcomeServiceInterface $outcomes;

    private StudentReviewServiceInterface $submissions;

    private ReviewModerationServiceInterface $moderation;

    private ReviewReportServiceInterface $reports;

    private InstructorQualityAlertServiceInterface $alerts;

    private AdminQualityDashboardServiceInterface $dashboard;

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
        $this->dashboard = app(AdminQualityDashboardServiceInterface::class);
        $this->bookings = app(BookingServiceInterface::class);

        $this->enableReviews();
        $this->seed(ReviewPermissionSeeder::class);
        $this->seed(LessonPermissionSeeder::class);
    }

    // ── 1–4. Access control ────────────────────────────────────────────

    public function test_authorized_administrator_can_access_the_quality_dashboard(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/reports/reviews-quality')
            ->assertOk();
    }

    public function test_student_cannot_access_the_dashboard(): void
    {
        $this->actingAs($this->reporterUser())
            ->get('/admin/reports/reviews-quality')
            ->assertForbidden();
    }

    public function test_instructor_cannot_access_the_dashboard(): void
    {
        $this->actingAs($this->instructorUser())
            ->get('/admin/reports/reviews-quality')
            ->assertForbidden();
    }

    public function test_unauthorized_administrator_is_denied(): void
    {
        // A "manager" who never received the dashboard permission
        // (e.g. the seeder never ran for their tenant/role).
        $unpermissioned = User::factory()->create(['status' => 'active']);

        $this->actingAs($unpermissioned)
            ->get('/admin/reports/reviews-quality')
            ->assertForbidden();

        $this->assertFalse(ReviewsQualityDashboard::canAccess());
    }

    // ── 5–6. Moderation summary counts ────────────────────────────────

    public function test_submitted_review_count_is_correct(): void
    {
        $this->enableReviews(['moderation_model' => 'pre_moderation']);
        $instructor = $this->instructorUser();
        $this->submitPublicReview($instructor);
        $this->submitPublicReview($instructor);

        $this->assertSame(2, $this->dashboard->summary()->submittedReviews);
    }

    public function test_flagged_review_count_is_correct(): void
    {
        $instructor = $this->instructorUser();
        $this->submitPublicReview($instructor, content: 'Contact me at leaky@example.com please.');

        $this->assertSame(1, $this->dashboard->summary()->flaggedReviews);
    }

    // ── 7–8. Rating metric exclusions ──────────────────────────────────

    public function test_private_feedback_is_excluded_from_public_rating_metrics(): void
    {
        $instructor = $this->instructorUser();
        $this->submitPrivateFeedback($instructor, overallRating: 1);

        $summary = $this->dashboard->summary();
        $this->assertSame(0, $summary->platformEligiblePublishedReviewCount);
        $this->assertNull($summary->platformAverageRating);
    }

    public function test_hidden_review_is_excluded_from_current_rating_metrics(): void
    {
        $instructor = $this->instructorUser();
        $review = $this->submitPublicReview($instructor, overallRating: 5)->fresh();
        $this->assertSame(1, $this->dashboard->summary()->platformEligiblePublishedReviewCount);

        $this->moderation->hide($review, $this->admin(), 'Routine check.');

        $this->assertSame(0, $this->dashboard->summary()->platformEligiblePublishedReviewCount);
        $this->assertNull($this->dashboard->summary()->platformAverageRating);
    }

    // ── 9–12. Report and alert workload counts ────────────────────────

    public function test_pending_report_count_is_correct(): void
    {
        $instructor = $this->instructorUser();
        $review = $this->submitPublicReview($instructor)->fresh();
        $this->reports->submit($review, $this->reporterUser(), new SubmitReviewReportData(reason: ReviewReportReason::Spam));

        $this->assertSame(1, $this->dashboard->summary()->pendingReports);
    }

    public function test_under_review_report_count_is_correct(): void
    {
        $instructor = $this->instructorUser();
        $review = $this->submitPublicReview($instructor)->fresh();
        $report = $this->reports->submit($review, $this->reporterUser(), new SubmitReviewReportData(reason: ReviewReportReason::Spam));
        $this->reports->startReview($report, $this->admin());

        $this->assertSame(0, $this->dashboard->summary()->pendingReports);
        $this->assertSame(1, $this->dashboard->summary()->reportsUnderReview);
    }

    public function test_open_quality_alert_count_is_correct(): void
    {
        $this->enableReviews(['quality_alerts_enabled' => true]);
        $instructor = $this->instructorUser();
        $this->submitPublicReview($instructor, overallRating: 1);

        $this->assertSame(1, $this->dashboard->summary()->openAlerts);
    }

    public function test_high_and_critical_severity_counts_are_correct(): void
    {
        $this->enableReviews(['quality_alerts_enabled' => true]);
        $instructor = $this->instructorUser();
        $review = $this->submitPublicReview($instructor)->fresh();
        $report = $this->reports->submit($review, $this->reporterUser(), new SubmitReviewReportData(reason: ReviewReportReason::AbusiveLanguage));
        $this->reports->uphold($report, $this->admin(), 'Confirmed.', ReviewReportResolutionAction::NoAction);

        // SeriousReviewReport is High severity per QualityAlertSeverityPolicy.
        $this->assertSame(1, $this->dashboard->summary()->highSeverityAlerts);
        $this->assertSame(0, $this->dashboard->summary()->criticalSeverityAlerts);
    }

    // ── 13–16. Low/high rated instructor lists ─────────────────────────

    public function test_low_rated_instructor_list_uses_eligible_published_aggregates(): void
    {
        $instructor = $this->instructorUser();
        $this->submitPublicReview($instructor, overallRating: 1);
        $this->submitPublicReview($instructor, overallRating: 2);
        $this->submitPublicReview($instructor, overallRating: 2);

        $lowRated = $this->dashboard->lowRatedInstructors();

        $this->assertCount(1, $lowRated);
        $this->assertSame($instructor->id, $lowRated->first()->instructorId);
        $this->assertEqualsWithDelta(5 / 3, $lowRated->first()->averageRating, 0.01);
    }

    public function test_highly_rated_instructor_list_uses_eligible_published_aggregates(): void
    {
        $instructor = $this->instructorUser();
        $this->submitPublicReview($instructor, overallRating: 5);
        $this->submitPublicReview($instructor, overallRating: 5);
        $this->submitPublicReview($instructor, overallRating: 4);

        $highlyRated = $this->dashboard->highlyRatedInstructors();

        $this->assertCount(1, $highlyRated);
        $this->assertSame($instructor->id, $highlyRated->first()->instructorId);
    }

    public function test_minimum_review_count_threshold_is_enforced(): void
    {
        $instructor = $this->instructorUser();
        $this->submitPublicReview($instructor, overallRating: 1); // only 1 review — below default min of 3

        $this->assertCount(0, $this->dashboard->lowRatedInstructors());
    }

    public function test_zero_review_instructor_is_excluded_from_low_rated_results(): void
    {
        $this->instructorUser(); // never reviewed at all — no aggregate row exists

        $this->assertCount(0, $this->dashboard->lowRatedInstructors());
    }

    // ── 17–19. No-show authoritative filtering ─────────────────────────

    public function test_instructor_no_show_count_uses_only_instructor_no_show(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->paidLesson($instructor);
        $this->outcomes->finalize($lesson->refresh(), LessonOutcome::InstructorNoShow);

        $this->assertSame(1, $this->dashboard->summary()->instructorNoShowCount);
    }

    public function test_student_no_show_is_excluded(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->paidLesson($instructor);
        $this->outcomes->finalize($lesson->refresh(), LessonOutcome::StudentNoShow);

        $this->assertSame(0, $this->dashboard->summary()->instructorNoShowCount);
    }

    public function test_technical_issue_is_excluded(): void
    {
        $instructor = $this->instructorUser();
        $lesson = $this->paidLesson($instructor);
        $this->outcomes->finalize($lesson->refresh(), LessonOutcome::TechnicalIssue);

        $this->assertSame(0, $this->dashboard->summary()->instructorNoShowCount);
    }

    // ── 20–21. Cancellation attribution ─────────────────────────────────

    public function test_instructor_attributed_cancellation_is_counted(): void
    {
        $instructor = $this->instructorUser();
        $booking = $this->confirmedBooking($instructor);
        $this->bookings->cancel($booking, new CancelBookingData(BookingActor::Instructor, 'Unavailable.'));

        $this->assertSame(1, $this->dashboard->summary()->instructorAttributedCancellationCount);
    }

    public function test_student_and_system_cancellations_are_excluded(): void
    {
        $instructor = $this->instructorUser();
        $studentBooking = $this->confirmedBooking($instructor);
        $this->bookings->cancel($studentBooking, new CancelBookingData(BookingActor::Student, 'Changed my mind.'));
        $systemBooking = $this->confirmedBooking($instructor);
        $this->bookings->cancel($systemBooking, new CancelBookingData(BookingActor::System, 'Payment lapsed.', expired: true));

        $this->assertSame(0, $this->dashboard->summary()->instructorAttributedCancellationCount);
    }

    // ── 22. Outcome overrides reflected ─────────────────────────────────

    public function test_outcome_overrides_are_reflected_in_dashboard_metrics(): void
    {
        $instructor = $this->instructorUser();
        $admin = $this->admin();
        $lesson = $this->paidLesson($instructor);
        $this->outcomes->finalize($lesson->refresh(), LessonOutcome::InstructorNoShow);
        $this->assertSame(1, $this->dashboard->summary()->instructorNoShowCount);

        $this->outcomes->override($lesson->refresh(), $admin, LessonOutcome::TechnicalIssue, 'Platform outage, not a no-show.');

        $this->assertSame(0, $this->dashboard->summary()->instructorNoShowCount);
    }

    // ── 23–25. Queue filters ─────────────────────────────────────────────

    public function test_review_queue_filters_work(): void
    {
        $instructorA = $this->instructorUser();
        $instructorB = $this->instructorUser();
        $this->submitPublicReview($instructorA, overallRating: 5);
        $this->submitPublicReview($instructorB, overallRating: 3);

        $page = $this->dashboard->moderationQueue(new ModerationQueueFilters(instructorId: $instructorA->id));

        $this->assertCount(1, $page->items());
        $this->assertSame($instructorA->id, $page->items()[0]->instructorId);
    }

    public function test_report_queue_filters_work(): void
    {
        $instructor = $this->instructorUser();
        $reviewA = $this->submitPublicReview($instructor)->fresh();
        $reviewB = $this->submitPublicReview($instructor)->fresh();
        $this->reports->submit($reviewA, $this->reporterUser(), new SubmitReviewReportData(reason: ReviewReportReason::Spam));
        $this->reports->submit($reviewB, $this->reporterUser(), new SubmitReviewReportData(reason: ReviewReportReason::AbusiveLanguage));

        $page = $this->dashboard->reportQueue(new ReportQueueFilters(reason: ReviewReportReason::AbusiveLanguage));

        $this->assertCount(1, $page->items());
        $this->assertSame('abusive_language', $page->items()[0]->reason);
    }

    public function test_alert_queue_filters_work(): void
    {
        $this->enableReviews(['quality_alerts_enabled' => true]);
        $instructorA = $this->instructorUser();
        $instructorB = $this->instructorUser();
        $this->submitPublicReview($instructorA, overallRating: 1);
        $this->submitPublicReview($instructorB, overallRating: 1);

        $page = $this->dashboard->alertQueue(new AlertQueueFilters(instructorId: $instructorA->id));

        $this->assertCount(1, $page->items());
    }

    // ── 26. Deterministic pagination ─────────────────────────────────────

    public function test_pagination_is_deterministic(): void
    {
        $instructor = $this->instructorUser();
        foreach (range(1, 3) as $i) {
            $this->submitPublicReview($instructor, content: "Distinct body {$i}.");
        }

        $firstCall = $this->dashboard->moderationQueue(new ModerationQueueFilters, perPage: 2);
        $secondCall = $this->dashboard->moderationQueue(new ModerationQueueFilters, perPage: 2);

        $this->assertSame(
            array_map(fn ($r) => $r->reviewId, $firstCall->items()),
            array_map(fn ($r) => $r->reviewId, $secondCall->items()),
        );
    }

    // ── 27–28. Date range safety ─────────────────────────────────────────

    public function test_date_ranges_use_utc_safe_boundaries(): void
    {
        $range = TrendDateRange::make(
            CarbonImmutable::parse('2026-01-05 23:00:00', 'America/New_York'),
            CarbonImmutable::parse('2026-01-10 23:00:00', 'America/New_York'),
        );

        $this->assertSame('UTC', $range->start->getTimezone()->getName());
        $this->assertSame('UTC', $range->end->getTimezone()->getName());
    }

    public function test_excessive_custom_date_range_is_bounded(): void
    {
        $range = TrendDateRange::make(
            CarbonImmutable::now()->subYears(2),
            CarbonImmutable::now(),
            maxDays: 90,
        );

        $this->assertLessThanOrEqual(90, $range->days());
    }

    // ── 29–30. Privacy ────────────────────────────────────────────────

    public function test_dashboard_dtos_contain_no_student_contact_information(): void
    {
        $instructor = $this->instructorUser();
        $this->submitPublicReview($instructor);
        $row = $this->dashboard->moderationQueue(new ModerationQueueFilters)->items()[0];

        $fields = array_keys(get_object_vars($row));
        foreach (['studentEmail', 'studentPhone', 'studentId', 'student_id'] as $forbidden) {
            $this->assertNotContains($forbidden, $fields);
        }
    }

    public function test_dashboard_contains_no_financial_or_compensation_information(): void
    {
        $instructor = $this->instructorUser();
        $this->submitPublicReview($instructor);

        $summaryFields = array_keys(get_object_vars($this->dashboard->summary()));
        $rowFields = array_keys(get_object_vars($this->dashboard->moderationQueue(new ModerationQueueFilters)->items()[0]));

        foreach (['revenue', 'price', 'compensation', 'payout', 'earnings'] as $forbidden) {
            $this->assertNotContains($forbidden, $summaryFields);
            $this->assertNotContains($forbidden, $rowFields);
        }
    }

    // ── 31–32. Action delegation / no direct mutation ────────────────────

    public function test_dashboard_actions_delegate_to_existing_services(): void
    {
        $this->enableReviews(['quality_alerts_enabled' => true]);
        $instructor = $this->instructorUser();
        $this->submitPublicReview($instructor, overallRating: 1);
        $alert = InstructorQualityAlert::query()->where('instructor_id', $instructor->id)->firstOrFail();
        $admin = $this->admin();

        $this->actingAs($admin);

        Livewire::test(AlertQueueWidget::class)
            ->callTableAction('resolve', $alert, data: [
                'reason' => 'Reviewed and addressed with the instructor.',
                'action' => InstructorQualityAlertResolutionAction::ContactInstructor->value,
            ])
            ->assertHasNoTableActionErrors();

        $alert->refresh();
        $this->assertSame(InstructorQualityAlertStatus::Resolved, $alert->status);
        $this->assertSame($admin->id, $alert->reviewed_by);
    }

    public function test_no_direct_review_or_alert_status_mutation_occurs(): void
    {
        $instructor = $this->instructorUser();
        $review = $this->submitPublicReview($instructor, overallRating: 1)->fresh();
        $alert = InstructorQualityAlert::query()->where('instructor_id', $instructor->id)->first();

        $reviewVersionBefore = $review->version;
        $alertVersionBefore = $alert?->version;

        // Every dashboard read method, called back to back.
        $this->dashboard->summary();
        $this->dashboard->moderationQueue(new ModerationQueueFilters);
        $this->dashboard->reportQueue(new ReportQueueFilters);
        $this->dashboard->alertQueue(new AlertQueueFilters);
        $this->dashboard->lowRatedInstructors();
        $this->dashboard->highlyRatedInstructors();

        $this->assertSame($reviewVersionBefore, $review->fresh()->version);
        if ($alert !== null) {
            $this->assertSame($alertVersionBefore, $alert->fresh()->version);
        }
    }

    // ── 33. No side effects from reads ──────────────────────────────────

    public function test_dashboard_reads_produce_no_domain_side_effects(): void
    {
        $instructor = $this->instructorUser();
        $this->submitPublicReview($instructor, overallRating: 1);

        $this->dashboard->summary();
        $this->dashboard->trendSeries(TrendDateRange::lastDays(7));

        $this->assertSame(0, DB::table('wallets')->count());
        $this->assertSame(0, DB::table('instructor_earnings')->count());
    }

    // ── 34. Regression ────────────────────────────────────────────────

    public function test_existing_phase_17h_to_17n_regression_unaffected(): void
    {
        $instructor = $this->instructorUser();
        $review = $this->submitPublicReview($instructor, content: 'DM me on @sketchy_handle for more info.')->fresh();
        $this->assertSame(StudentReviewStatus::Flagged, $review->status);

        $approved = $this->moderation->approve($review, $this->admin(), 'Reviewed manually — fine.');
        $this->assertSame(StudentReviewStatus::Published, $approved->status);

        $response = $this->get(route('instructors.show', $instructor));
        $response->assertOk();
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
        $settings->quality_dashboard_low_rating_threshold = 2.5;
        $settings->quality_dashboard_high_rating_threshold = 4.5;
        $settings->quality_dashboard_min_review_count = 3;

        foreach ($overrides as $key => $value) {
            $settings->{$key} = $value;
        }

        $settings->save();
    }
}
