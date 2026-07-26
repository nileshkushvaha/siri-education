<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Booking\Enums\BookingPaymentStatus;
use App\Filament\Widgets\Quality\ReportQueueWidget;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\Enums\LessonOutcome;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Lesson;
use App\Models\LessonReview;
use App\Models\LessonReviewEligibility;
use App\Models\ReviewReport;
use App\Models\User;
use App\Reviews\Contracts\ReviewModerationServiceInterface;
use App\Reviews\Contracts\ReviewReportServiceInterface;
use App\Reviews\Contracts\StudentReviewServiceInterface;
use App\Reviews\DTOs\SubmitReviewReportData;
use App\Reviews\DTOs\SubmitStudentReviewData;
use App\Reviews\Enums\ReviewReportReason;
use App\Reviews\Enums\ReviewReportStatus;
use App\Reviews\Enums\StudentReviewStatus;
use App\Settings\ReviewSettings;
use Database\Seeders\ReviewPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The report-resolution mutation actions on ReportQueueWidget
 * (start-review/uphold/dismiss/mark-duplicate/mark-remaining-duplicate),
 * every one delegating exclusively to ReviewReportService, plus
 * authorization hardening: the reviewed instructor cannot resolve a
 * report about their own review.
 */
class ReviewReportWidgetActionsTest extends TestCase
{
    use RefreshDatabase;

    private LessonLifecycleServiceInterface $lifecycle;

    private LessonOutcomeServiceInterface $outcomes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lifecycle = app(LessonLifecycleServiceInterface::class);
        $this->outcomes = app(LessonOutcomeServiceInterface::class);

        $this->enableReviews();
    }

    public function test_start_review_action_transitions_pending_to_under_review(): void
    {
        $report = $this->reportedReview();
        $this->actingAs($this->admin());

        Livewire::test(ReportQueueWidget::class)
            ->callTableAction('startReview', $report)
            ->assertHasNoTableActionErrors();

        $this->assertSame(ReviewReportStatus::UnderReview, $report->fresh()->status);
    }

    public function test_uphold_action_with_hide_review_resolution_hides_the_review(): void
    {
        $report = $this->reportedReview();
        $this->actingAs($this->admin());

        Livewire::test(ReportQueueWidget::class)
            ->callTableAction('uphold', $report, data: [
                'resolution_reason' => 'Confirmed fake review after investigation.',
                'action' => 'hide_review',
            ])
            ->assertHasNoTableActionErrors();

        $report->refresh();
        $this->assertSame(ReviewReportStatus::Upheld, $report->status);
        $this->assertSame(StudentReviewStatus::Hidden, $report->review->fresh()->status);
    }

    public function test_uphold_action_requires_a_reason(): void
    {
        $report = $this->reportedReview();
        $this->actingAs($this->admin());

        Livewire::test(ReportQueueWidget::class)
            ->callTableAction('uphold', $report, data: ['resolution_reason' => '', 'action' => 'no_action'])
            ->assertHasTableActionErrors(['resolution_reason']);

        $this->assertSame(ReviewReportStatus::Pending, $report->fresh()->status);
    }

    public function test_dismiss_action_with_restore_action_restores_a_hidden_review(): void
    {
        $review = $this->publishedReview();
        $report = $this->reportFor($review);
        $admin = $this->admin();
        app(ReviewModerationServiceInterface::class)->hide($review->fresh(), $admin, 'Hidden pending investigation.');

        $this->actingAs($admin);

        Livewire::test(ReportQueueWidget::class)
            ->callTableAction('dismiss', $report, data: [
                'resolution_reason' => 'Investigation found the review was legitimate.',
                'action' => 'restore_review',
            ])
            ->assertHasNoTableActionErrors();

        $report->refresh();
        $this->assertSame(ReviewReportStatus::Dismissed, $report->status);
        $this->assertSame(StudentReviewStatus::Published, $report->review->fresh()->status);
    }

    public function test_mark_duplicate_action_marks_a_report_duplicate(): void
    {
        $report = $this->reportedReview();
        $this->actingAs($this->admin());

        Livewire::test(ReportQueueWidget::class)
            ->callTableAction('markDuplicate', $report, data: ['resolution_reason' => null])
            ->assertHasNoTableActionErrors();

        $this->assertSame(ReviewReportStatus::Duplicate, $report->fresh()->status);
    }

    public function test_mark_remaining_duplicate_closes_other_open_reports_on_the_same_review(): void
    {
        $review = $this->publishedReview();
        $first = $this->reportFor($review, ReviewReportReason::Spam);
        $second = $this->reportFor($review, ReviewReportReason::HateOrHarassment);
        $admin = $this->admin();
        $this->actingAs($admin);

        Livewire::test(ReportQueueWidget::class)
            ->callTableAction('markRemainingDuplicate', $first, data: ['resolution_reason' => 'One primary report is enough to act on.'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(ReviewReportStatus::Duplicate, $second->fresh()->status);
    }

    public function test_a_student_has_no_resolve_permission_at_all(): void
    {
        $report = $this->reportedReview();
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $student = User::factory()->create(['status' => 'active']);
        $student->assignRole('student');

        $this->assertFalse($student->can('resolve', $report));
    }

    public function test_reviewed_instructor_cannot_resolve_a_report_about_their_own_review(): void
    {
        $this->seed(ReviewPermissionSeeder::class);
        $report = $this->reportedReview();
        $ownInstructor = $report->review->instructor;
        // Hypothetical future role change: even holding the permission
        // directly must not let the reviewed instructor resolve it.
        $ownInstructor->givePermissionTo('Resolve:ReviewReport');

        $this->assertFalse($ownInstructor->can('resolve', $report));
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function paidLesson(?User $instructor = null): Lesson
    {
        $endsAt = now()->subHours(2)->startOfHour();

        $booking = Booking::factory()->confirmed()->create(array_filter([
            'booking_type_id' => BookingType::factory()->paid(),
            'instructor_id' => $instructor?->id,
            'starts_at' => $endsAt->copy()->subMinutes(60),
            'ends_at' => $endsAt,
            'payment_status' => BookingPaymentStatus::Paid,
            'price' => '499.00',
            'currency' => 'INR',
        ]));

        return $this->lifecycle->createFromBooking($booking);
    }

    private function publishedReview(): LessonReview
    {
        $lesson = $this->paidLesson();
        $this->outcomes->finalize($lesson->refresh(), LessonOutcome::Completed);
        $eligibility = LessonReviewEligibility::query()->where('lesson_id', $lesson->id)->firstOrFail();

        $result = app(StudentReviewServiceInterface::class)->submit($eligibility, $eligibility->student, new SubmitStudentReviewData(
            overallRating: 5,
            content: 'A genuinely helpful and well-structured lesson overall.',
        ));

        return app(ReviewModerationServiceInterface::class)->approve($result->review, $this->admin());
    }

    private function reportFor(LessonReview $review, ReviewReportReason $reason = ReviewReportReason::Spam): ReviewReport
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $this->seed(ReviewPermissionSeeder::class);
        $reporter = User::factory()->create(['status' => 'active']);
        $reporter->assignRole('student');

        return app(ReviewReportServiceInterface::class)->submit($review->fresh(), $reporter, new SubmitReviewReportData(reason: $reason));
    }

    private function reportedReview(): ReviewReport
    {
        return $this->reportFor($this->publishedReview());
    }

    private function admin(): User
    {
        $this->seed(ReviewPermissionSeeder::class);
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('manager');

        return $admin;
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
        $settings->moderation_model = 'pre_moderation';
        $settings->auto_publish_clean_reviews = true;
        $settings->review_reporting_enabled = true;

        foreach ($overrides as $key => $value) {
            $settings->{$key} = $value;
        }

        $settings->save();
    }
}
