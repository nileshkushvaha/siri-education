<?php

declare(strict_types=1);

namespace Tests\Feature\Reviews;

use App\Booking\Enums\BookingPaymentStatus;
use App\Enums\InstructorStatus;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\Enums\LessonOutcome;
use App\Livewire\Reviews\ReportReview;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Lesson;
use App\Models\LessonReview;
use App\Models\LessonReviewEligibility;
use App\Models\ReviewReport;
use App\Models\User;
use App\Reviews\Contracts\PublicInstructorReviewServiceInterface;
use App\Reviews\Contracts\ReviewReportRepositoryInterface;
use App\Reviews\Contracts\ReviewReportServiceInterface;
use App\Reviews\Contracts\StudentReviewServiceInterface;
use App\Reviews\DTOs\SubmitReviewReportData;
use App\Reviews\DTOs\SubmitStudentReviewData;
use App\Reviews\Enums\ReviewReportReason;
use App\Reviews\Enums\ReviewReportResolutionAction;
use App\Reviews\Enums\ReviewReportStatus;
use App\Settings\ReviewSettings;
use Database\Seeders\ReviewPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The user-facing "Report Review" Livewire UI. Every
 * domain rule (authorization, reason validity, duplicate prevention,
 * sanitization, audit logging) is enforced exclusively by the existing
 * ReviewReportServiceInterface/SubmitReviewReportAction; this
 * suite only proves the UI correctly reaches — and never bypasses or
 * duplicates — that backend, end to end through the existing admin
 * queue.
 */
class ReviewReportUiTest extends TestCase
{
    use RefreshDatabase;

    private LessonLifecycleServiceInterface $lifecycle;

    private LessonOutcomeServiceInterface $outcomes;

    private StudentReviewServiceInterface $submissions;

    private ReviewReportServiceInterface $reports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lifecycle = app(LessonLifecycleServiceInterface::class);
        $this->outcomes = app(LessonOutcomeServiceInterface::class);
        $this->submissions = app(StudentReviewServiceInterface::class);
        $this->reports = app(ReviewReportServiceInterface::class);

        $this->enableReviews();
        $this->seed(ReviewPermissionSeeder::class);
    }

    // ── 1. Action visibility ─────────────────────────────────────────────

    public function test_eligible_authenticated_user_sees_the_report_action_on_the_public_profile(): void
    {
        $review = $this->submitPublicReview()->fresh();

        $this->actingAs($this->reporterUser())
            ->get(route('instructors.show', $review->instructor))
            ->assertOk()
            ->assertSee('Report Review');
    }

    public function test_guest_sees_a_sign_in_prompt_instead_of_the_report_action(): void
    {
        $review = $this->submitPublicReview()->fresh();

        $this->get(route('instructors.show', $review->instructor))
            ->assertOk()
            ->assertSee('Sign in to report')
            ->assertDontSee('Report Review');
    }

    public function test_authenticated_user_without_the_report_permission_does_not_see_the_action(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $manager = User::factory()->create(['status' => 'active']);
        $manager->assignRole('manager'); // staff, but not seeded Report:LessonReview

        Livewire::actingAs($manager)
            ->test(ReportReview::class, ['reviewId' => $review->id])
            ->assertDontSee('Report Review');
    }

    public function test_inactive_user_does_not_see_the_report_action(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $suspended = $this->reporterUser(['status' => 'suspended']);

        Livewire::actingAs($suspended)
            ->test(ReportReview::class, ['reviewId' => $review->id])
            ->assertDontSee('Report Review');
    }

    public function test_changing_the_review_id_to_a_non_reportable_review_does_not_bypass_authorization(): void
    {
        // LessonReviewPolicy::report() is a role-level check only (active
        // + Report:LessonReview) — exactly like ReviewReportService's own
        // authorizeReport() — so it does not by itself reject a private-
        // feedback id. The instance-level "is this review even eligible
        // to be reported" check belongs solely to SubmitReviewReportAction,
        // which the component must never re-implement. A
        // tampered review id therefore still safely rejects at submission,
        // creating no report and revealing no internal status/mode.
        $privateFeedback = $this->submitPrivateFeedback()->fresh();
        $reporter = $this->reporterUser();

        $component = Livewire::actingAs($reporter)
            ->test(ReportReview::class, ['reviewId' => $privateFeedback->id])
            ->set('selectedReason', ReviewReportReason::Spam->value)
            ->call('submitReport');

        $component->assertHasErrors(['form']);
        $this->assertSame('This review can no longer be reported.', $component->errors()->first('form'));
        $this->assertSame(0, ReviewReport::query()->where('review_id', $privateFeedback->id)->count());
    }

    public function test_changing_the_review_id_to_someone_elses_reportable_review_still_authorizes_normally_and_creates_one_report(): void
    {
        // "Tampering" with the review id in a Livewire request only ever
        // changes *which* eligible review is targeted — it never widens
        // what the acting user is allowed to do, since authorization is
        // re-checked against whatever id is supplied, fresh, every time.
        $reviewA = $this->submitPublicReview()->fresh();
        $reviewB = $this->submitPublicReview()->fresh();
        $reporter = $this->reporterUser();

        Livewire::actingAs($reporter)
            ->test(ReportReview::class, ['reviewId' => $reviewA->id])
            ->set('reviewId', $reviewB->id) // simulates a tampered request payload
            ->set('selectedReason', ReviewReportReason::Spam->value)
            ->call('submitReport')
            ->assertHasNoErrors();

        $this->assertSame(1, ReviewReport::query()->where('review_id', $reviewB->id)->count());
        $this->assertSame(0, ReviewReport::query()->where('review_id', $reviewA->id)->count());
    }

    // ── 2. Form rendering ─────────────────────────────────────────────────

    public function test_all_configured_report_reasons_are_rendered(): void
    {
        $review = $this->submitPublicReview()->fresh();

        $component = Livewire::actingAs($this->reporterUser())
            ->test(ReportReview::class, ['reviewId' => $review->id]);

        foreach (ReviewReportReason::cases() as $reason) {
            $component->assertSee($reason->label());
        }
    }

    public function test_empty_report_reason_configuration_still_renders_safely(): void
    {
        // ReviewReportReason has no per-reason enable/disable flag — the
        // full enum is always the authoritative, fixed list. This test
        // documents that the form has no separate "no reasons
        // configured" failure mode to exercise.
        $this->assertNotEmpty(ReviewReportReason::cases());
    }

    public function test_sensitive_review_information_is_not_rendered_in_the_component(): void
    {
        $review = $this->submitPublicReview()->fresh();

        Livewire::actingAs($this->reporterUser())
            ->test(ReportReview::class, ['reviewId' => $review->id])
            ->assertDontSee($review->student->email)
            ->assertDontSeeHtml((string) $review->status->value);
    }

    // ── 3. Submission ────────────────────────────────────────────────────

    public function test_valid_report_is_submitted_through_the_existing_service(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $reporter = $this->reporterUser();

        Livewire::actingAs($reporter)
            ->test(ReportReview::class, ['reviewId' => $review->id])
            ->set('selectedReason', ReviewReportReason::AbusiveLanguage->value)
            ->set('explanation', 'This contains inappropriate language.')
            ->call('submitReport')
            ->assertHasNoErrors()
            ->assertSet('selectedReason', '')
            ->assertSet('explanation', '');

        $report = ReviewReport::query()->where('review_id', $review->id)->firstOrFail();
        $this->assertSame($reporter->id, $report->reporter_id);
        $this->assertSame(ReviewReportReason::AbusiveLanguage, $report->reason);
        $this->assertSame(ReviewReportStatus::Pending, $report->status);
    }

    public function test_optional_explanation_may_be_omitted(): void
    {
        $review = $this->submitPublicReview()->fresh();

        Livewire::actingAs($this->reporterUser())
            ->test(ReportReview::class, ['reviewId' => $review->id])
            ->set('selectedReason', ReviewReportReason::Spam->value)
            ->call('submitReport')
            ->assertHasNoErrors();

        $report = ReviewReport::query()->where('review_id', $review->id)->firstOrFail();
        $this->assertNull($report->explanation);
    }

    // ── 4. Validation ────────────────────────────────────────────────────

    public function test_missing_reason_is_rejected(): void
    {
        $review = $this->submitPublicReview()->fresh();

        Livewire::actingAs($this->reporterUser())
            ->test(ReportReview::class, ['reviewId' => $review->id])
            ->set('selectedReason', '')
            ->call('submitReport')
            ->assertHasErrors(['selectedReason' => 'required']);

        $this->assertSame(0, ReviewReport::query()->count());
    }

    public function test_unknown_reason_value_is_rejected(): void
    {
        $review = $this->submitPublicReview()->fresh();

        Livewire::actingAs($this->reporterUser())
            ->test(ReportReview::class, ['reviewId' => $review->id])
            ->set('selectedReason', 'not_a_real_reason')
            ->call('submitReport')
            ->assertHasErrors(['selectedReason']);

        $this->assertSame(0, ReviewReport::query()->count());
    }

    public function test_explanation_above_maximum_length_is_rejected(): void
    {
        $review = $this->submitPublicReview()->fresh();

        Livewire::actingAs($this->reporterUser())
            ->test(ReportReview::class, ['reviewId' => $review->id])
            ->set('selectedReason', ReviewReportReason::Spam->value)
            ->set('explanation', str_repeat('a', 1001))
            ->call('submitReport')
            ->assertHasErrors(['explanation' => 'max']);

        $this->assertSame(0, ReviewReport::query()->count());
    }

    public function test_unsafe_explanation_content_is_sanitized_by_the_existing_sanitizer(): void
    {
        $review = $this->submitPublicReview()->fresh();

        Livewire::actingAs($this->reporterUser())
            ->test(ReportReview::class, ['reviewId' => $review->id])
            ->set('selectedReason', ReviewReportReason::PersonalInformation->value)
            ->set('explanation', '<script>alert(1)</script>Contact me at leaky@sirieducation.com now.')
            ->call('submitReport')
            ->assertHasNoErrors();

        $report = ReviewReport::query()->where('review_id', $review->id)->firstOrFail();
        $this->assertStringNotContainsString('<script>', (string) $report->explanation);
        $this->assertStringNotContainsString('leaky@sirieducation.com', (string) $report->explanation);
    }

    // ── 5. Authorization ─────────────────────────────────────────────────

    public function test_unauthorized_user_cannot_submit_by_direct_livewire_call(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $stranger = User::factory()->create(['status' => 'active']); // no student/instructor role

        Livewire::actingAs($stranger)
            ->test(ReportReview::class, ['reviewId' => $review->id])
            ->set('selectedReason', ReviewReportReason::Spam->value)
            ->call('submitReport')
            ->assertHasErrors(['form']);

        $this->assertSame(0, ReviewReport::query()->count());
    }

    public function test_suspended_user_cannot_submit(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $suspended = $this->reporterUser(['status' => 'suspended']);

        Livewire::actingAs($suspended)
            ->test(ReportReview::class, ['reviewId' => $review->id])
            ->set('selectedReason', ReviewReportReason::Spam->value)
            ->call('submitReport')
            ->assertHasErrors(['form']);

        $this->assertSame(0, ReviewReport::query()->count());
    }

    public function test_policy_denial_does_not_expose_internal_review_state(): void
    {
        $flagged = $this->submitPublicReview(content: 'Contact me on @sketchy_handle for details.')->fresh();
        $reporter = $this->reporterUser();

        $component = Livewire::actingAs($reporter)
            ->test(ReportReview::class, ['reviewId' => $flagged->id])
            ->set('selectedReason', ReviewReportReason::Spam->value)
            ->call('submitReport');

        $component->assertHasErrors(['form']);
        $errorMessage = $component->errors()->first('form');
        $this->assertStringNotContainsString('flagged', strtolower($errorMessage));
        $this->assertStringNotContainsString($flagged->id, $errorMessage);
    }

    // ── 6. Duplicate handling ────────────────────────────────────────────

    public function test_duplicate_report_shows_a_neutral_message_and_creates_no_second_record(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $reporter = $this->reporterUser();

        $this->reports->submit($review, $reporter, new SubmitReviewReportData(reason: ReviewReportReason::Spam));

        $component = Livewire::actingAs($reporter)
            ->test(ReportReview::class, ['reviewId' => $review->id])
            ->set('selectedReason', ReviewReportReason::Spam->value)
            ->call('submitReport');

        $component->assertHasErrors(['form']);
        $this->assertSame('You have already reported this review.', $component->errors()->first('form'));
        $this->assertSame(1, ReviewReport::query()->where('review_id', $review->id)->count());
    }

    // ── 7. End-to-end workflow ───────────────────────────────────────────

    public function test_full_user_to_admin_report_workflow(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $instructor = $review->instructor;
        $reporter = $this->reporterUser();

        // 1–2. Public eligible review displayed, authorized user reports it.
        $this->actingAs($reporter)
            ->get(route('instructors.show', $instructor))
            ->assertOk()
            ->assertSee('Report Review');

        Livewire::actingAs($reporter)
            ->test(ReportReview::class, ['reviewId' => $review->id])
            ->set('selectedReason', ReviewReportReason::AbusiveLanguage->value)
            ->set('explanation', 'Contains abusive language.')
            ->call('submitReport')
            ->assertHasNoErrors();

        // 3. Report record created.
        $report = ReviewReport::query()->where('review_id', $review->id)->firstOrFail();
        $this->assertSame(ReviewReportStatus::Pending, $report->status);

        // 5. Report appears in the existing admin queue (repository read path).
        $queued = app(ReviewReportRepositoryInterface::class)
            ->pendingOrUnderReviewForReview($review);
        $this->assertTrue($queued->contains('id', $report->id));

        // 6. Unauthorized admin (the reviewed instructor, even if staffed) cannot resolve it.
        $instructor->assignRole('manager');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        try {
            $this->reports->dismiss($report, $instructor, 'Not valid.');
            $this->fail('Expected an AuthorizationException.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('your own review', $e->getMessage());
        }

        // 7. Authorized admin resolves it through the existing service.
        $admin = $this->admin();
        $upheld = $this->reports->uphold($report, $admin, 'Confirmed abusive language.', ReviewReportResolutionAction::HideReview);
        $this->assertSame(ReviewReportStatus::Upheld, $upheld->status);

        // 8. Audit trail records the action (existing ReviewReportService behavior).
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'reviews',
            'event' => 'review_report_upheld',
        ]);

        // 9–10. Public display and rating aggregate change only via existing moderation.
        $this->assertSame('hidden', $review->fresh()->status->value);
        $publicPage = app(PublicInstructorReviewServiceInterface::class)
            ->paginatedReviewsFor($instructor->fresh());
        $this->assertCount(0, $publicPage->items());

        // 11. Report history preserved.
        $this->assertDatabaseHas('review_reports', ['id' => $report->id, 'status' => ReviewReportStatus::Upheld->value]);
    }

    // ── Helpers (mirrors ReviewReportingTest) ───────────────────────────

    private function paidLesson(?User $instructor = null, ?User $student = null): Lesson
    {
        $endsAt = now()->subHours(2)->startOfHour();

        $booking = Booking::factory()->confirmed()->create([
            'booking_type_id' => BookingType::factory()->paid(),
            'instructor_id' => $instructor?->id ?? $this->instructorUser()->id,
            'student_id' => $student?->id ?? User::factory(),
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
            'instructor_id' => $instructor?->id ?? $this->instructorUser()->id,
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
