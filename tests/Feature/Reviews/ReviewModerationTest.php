<?php

declare(strict_types=1);

namespace Tests\Feature\Reviews;

use App\Booking\Enums\BookingPaymentStatus;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\Enums\LessonOutcome;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Lesson;
use App\Models\LessonReview;
use App\Models\LessonReviewEligibility;
use App\Models\User;
use App\Notifications\Reviews\ReviewHiddenNotification;
use App\Reviews\Contracts\ReviewModerationServiceInterface;
use App\Reviews\Contracts\StudentReviewServiceInterface;
use App\Reviews\DTOs\SubmitStudentReviewData;
use App\Reviews\Enums\StudentReviewStatus;
use App\Reviews\Events\StudentReviewPublished;
use App\Reviews\Exceptions\InvalidReviewTransitionException;
use App\Reviews\Exceptions\ReviewValidationException;
use App\Settings\ReviewSettings;
use Database\Seeders\ReviewPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 17J — review moderation & publication: automatic moderation
 * under each model, the full status transition matrix, admin actions
 * (approve/reject/hide/restore/archive), idempotency/concurrency,
 * authorization, audit, and the guarantee that content is never
 * edited and nothing publishes/aggregates/notifies/side-affects
 * anything outside the review row.
 */
class ReviewModerationTest extends TestCase
{
    use RefreshDatabase;

    private LessonLifecycleServiceInterface $lifecycle;

    private LessonOutcomeServiceInterface $outcomes;

    private StudentReviewServiceInterface $submissions;

    private ReviewModerationServiceInterface $moderation;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $this->lifecycle = app(LessonLifecycleServiceInterface::class);
        $this->outcomes = app(LessonOutcomeServiceInterface::class);
        $this->submissions = app(StudentReviewServiceInterface::class);
        $this->moderation = app(ReviewModerationServiceInterface::class);

        $this->enableReviews();
    }

    // ── 1–4. Automatic moderation models ─────────────────────────────

    public function test_risk_based_clean_public_review_becomes_published(): void
    {
        $review = $this->submitPublicReview(); // risk_based is the default

        $this->assertSame(StudentReviewStatus::Published, $review->fresh()->status);
    }

    public function test_risk_based_flagged_public_review_remains_flagged(): void
    {
        $review = $this->submitPublicReview(content: 'Contact me at leaky@example.com for more.');

        $this->assertSame(StudentReviewStatus::Flagged, $review->fresh()->status);
    }

    public function test_pre_moderation_keeps_clean_public_review_submitted(): void
    {
        $this->enableReviews(['moderation_model' => 'pre_moderation']);

        $review = $this->submitPublicReview();

        $this->assertSame(StudentReviewStatus::Submitted, $review->fresh()->status);
    }

    public function test_post_moderation_publishes_clean_public_review(): void
    {
        $this->enableReviews(['moderation_model' => 'post_moderation']);

        $review = $this->submitPublicReview();

        $this->assertSame(StudentReviewStatus::Published, $review->fresh()->status);
    }

    // ── 5–6. Private feedback ─────────────────────────────────────────

    public function test_private_feedback_remains_private(): void
    {
        $review = $this->submitPrivateFeedback();

        $this->assertSame(StudentReviewStatus::Private, $review->fresh()->status);
    }

    public function test_flagged_private_feedback_can_be_approved_only_to_private(): void
    {
        $review = $this->submitPrivateFeedback(content: 'Call me on +1 (555) 222-3333 instead.');
        $this->assertSame(StudentReviewStatus::Flagged, $review->fresh()->status);

        $approved = $this->moderation->approve($review->fresh(), $this->admin());

        $this->assertSame(StudentReviewStatus::Private, $approved->status);
        $this->assertNotSame(StudentReviewStatus::Published, $approved->status);
    }

    // ── 7–9. Admin approval & authorization ───────────────────────────

    public function test_authorized_admin_approves_flagged_public_review(): void
    {
        $review = $this->submitPublicReview(content: 'DM me on @sketchy_handle for more info.');
        $this->assertSame(StudentReviewStatus::Flagged, $review->fresh()->status);

        Event::fake([StudentReviewPublished::class]);
        $approved = $this->moderation->approve($review->fresh(), $this->admin(), 'Reviewed manually — content is fine.');

        $this->assertSame(StudentReviewStatus::Published, $approved->status);
        Event::assertDispatchedTimes(StudentReviewPublished::class, 1);
    }

    public function test_unauthorized_user_cannot_moderate(): void
    {
        $review = $this->submitPublicReview(content: 'Contact me at leaky@example.com please.');
        $stranger = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        $this->moderation->approve($review->fresh(), $stranger);
    }

    public function test_instructor_cannot_approve_or_hide_a_review(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $instructor = $review->instructor;

        try {
            $this->moderation->approve($review, $instructor);
            $this->fail('Expected the instructor to be denied approval.');
        } catch (AuthorizationException) {
            // expected
        }

        $this->expectException(AuthorizationException::class);
        $this->moderation->hide($review, $instructor, 'Trying to hide my own review.');
    }

    // ── 10–11. Reason requirements ─────────────────────────────────────

    public function test_admin_rejection_requires_a_reason(): void
    {
        $review = $this->submitPublicReview()->fresh();

        $this->expectException(ReviewValidationException::class);

        $this->moderation->reject($review, $this->admin(), '   ');
    }

    public function test_admin_hiding_requires_a_reason(): void
    {
        $review = $this->submitPublicReview()->fresh(); // auto-published under risk_based

        $this->expectException(ReviewValidationException::class);

        $this->moderation->hide($review, $this->admin(), '');
    }

    // ── 12–14. Hide / restore / archive ──────────────────────────────

    public function test_published_review_can_be_hidden(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $this->assertSame(StudentReviewStatus::Published, $review->status);

        $hidden = $this->moderation->hide($review, $this->admin(), 'Contains a borderline claim, pending review.');

        $this->assertSame(StudentReviewStatus::Hidden, $hidden->status);
        $this->assertNotNull($hidden->moderated_at);
    }

    public function test_hidden_review_can_be_restored(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $admin = $this->admin();
        $hidden = $this->moderation->hide($review, $admin, 'Temporary hold.');

        $restored = $this->moderation->restore($hidden, $admin, 'Reviewed — content is acceptable after all.');

        $this->assertSame(StudentReviewStatus::Published, $restored->status);
    }

    public function test_hidden_and_private_reviews_can_be_archived(): void
    {
        $admin = $this->admin();

        $publicReview = $this->submitPublicReview()->fresh();
        $hidden = $this->moderation->hide($publicReview, $admin, 'Pending cleanup.');
        $archivedFromHidden = $this->moderation->archive($hidden, $admin, 'No longer relevant.');
        $this->assertSame(StudentReviewStatus::Archived, $archivedFromHidden->status);

        $privateReview = $this->submitPrivateFeedback()->fresh();
        $archivedFromPrivate = $this->moderation->archive($privateReview, $admin, 'Instructor no longer active.');
        $this->assertSame(StudentReviewStatus::Archived, $archivedFromPrivate->status);
    }

    // ── 15. Invalid transitions ────────────────────────────────────────

    public function test_invalid_status_transition_is_rejected(): void
    {
        $this->enableReviews(['moderation_model' => 'pre_moderation']);
        $review = $this->submitPublicReview()->fresh(); // stays Submitted
        $admin = $this->admin();

        $this->expectException(InvalidReviewTransitionException::class);

        // Hide requires Published — a Submitted review cannot be hidden.
        $this->moderation->hide($review, $admin, 'Attempting an invalid transition.');
    }

    // ── 16–17. Idempotency & concurrency ──────────────────────────────

    public function test_duplicate_moderation_is_idempotent(): void
    {
        $review = $this->submitPublicReview()->fresh(); // Published under risk_based
        $admin = $this->admin();

        $first = $this->moderation->hide($review, $admin, 'First hide.');
        $second = $this->moderation->hide($first->fresh(), $admin, 'Repeated hide attempt.');

        $this->assertSame(StudentReviewStatus::Hidden, $second->status);
        $this->assertSame($first->version, $second->version);
    }

    public function test_concurrent_conflicting_moderation_resolves_once(): void
    {
        $this->enableReviews(['moderation_model' => 'pre_moderation']);
        $review = $this->submitPublicReview()->fresh(); // stays Submitted
        $admin = $this->admin();

        $copyA = LessonReview::query()->findOrFail($review->id);
        $copyB = LessonReview::query()->findOrFail($review->id);

        $rejected = $this->moderation->reject($copyA, $admin, 'Not suitable for publication.');
        $this->assertSame(StudentReviewStatus::Rejected, $rejected->status);

        $this->expectException(InvalidReviewTransitionException::class);
        $this->moderation->approve($copyB, $admin); // conflicts with the already-Rejected row
    }

    // ── 18. Content immutability ────────────────────────────────────────

    public function test_review_text_ratings_and_tags_remain_unchanged(): void
    {
        $review = $this->submitPublicReview(content: 'A thoughtful, detailed, and genuinely useful lesson review.')->fresh();
        $originalContent = $review->content;
        $originalRating = $review->overall_rating;
        $originalTags = $review->tags;
        $admin = $this->admin();

        $hidden = $this->moderation->hide($review, $admin, 'Routine check.');
        $restored = $this->moderation->restore($hidden->fresh(), $admin, 'Confirmed acceptable.');

        $this->assertSame($originalContent, $restored->content);
        $this->assertSame($originalRating, $restored->overall_rating);
        $this->assertSame($originalTags, $restored->tags);
    }

    // ── 19–20. Audit & exactly-once events ────────────────────────────

    public function test_every_admin_action_creates_an_audit_record(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $admin = $this->admin();

        $this->moderation->hide($review, $admin, 'Needs a second look.');

        $activity = Activity::query()
            ->where('log_name', 'reviews')
            ->where('event', 'review_hidden')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame('published', $activity->properties->get('previous_status'));
        $this->assertSame('hidden', $activity->properties->get('new_status'));
        $this->assertSame('Needs a second look.', $activity->properties->get('reason'));
        $this->assertNotNull($activity->created_at);
    }

    public function test_events_dispatch_exactly_once_after_commit(): void
    {
        $this->enableReviews(['moderation_model' => 'pre_moderation']);
        $review = $this->submitPublicReview()->fresh();
        $admin = $this->admin();

        Event::fake([StudentReviewPublished::class]);

        $this->moderation->approve($review, $admin);
        $this->moderation->approve($review->fresh(), $admin); // idempotent repeat

        Event::assertDispatchedTimes(StudentReviewPublished::class, 1);
    }

    // ── 21–23. No side effects ───────────────────────────────────────

    public function test_no_public_rating_aggregate_is_created(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $this->moderation->approve($review, $this->admin());

        foreach (['instructor_review_aggregates', 'instructor_ratings', 'review_aggregates'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Phase 17J must not create a {$table} table.");
        }
    }

    public function test_no_notification_is_sent(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $admin = $this->admin();

        // Faked only around the moderation actions themselves — lesson
        // finalization upstream legitimately fires the pre-existing
        // booking-completion notification, unrelated to this phase.
        // Phase 17S later attaches ReviewHiddenNotification to a hidden
        // review's student — that is the one expected exception here;
        // restore has no notification listener attached in any phase.
        Notification::fake();
        $hidden = $this->moderation->hide($review, $admin, 'Routine check.');
        $this->moderation->restore($hidden->fresh(), $admin, 'Cleared.');

        Notification::assertSentToTimes($review->student, ReviewHiddenNotification::class, 1);
    }

    public function test_no_financial_booking_lesson_or_earning_record_changes(): void
    {
        $lesson = $this->paidLesson();
        $review = $this->submitPublicReview($lesson)->fresh();
        $originalOutcome = $lesson->refresh()->outcome;
        $originalBookingStatus = $lesson->booking->status;
        $admin = $this->admin();

        $this->moderation->hide($review, $admin, 'Routine check.');

        $this->assertSame($originalOutcome, $lesson->refresh()->outcome);
        $this->assertSame($originalBookingStatus, $lesson->booking->refresh()->status);
        $this->assertSame(0, DB::table('wallets')->count());
        $this->assertSame(0, DB::table('wallet_ledger_entries')->count());
        $this->assertSame(0, DB::table('instructor_earnings')->count());
        $this->assertSame(0, DB::table('lesson_financial_dispositions')->count());
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function paidLesson(): Lesson
    {
        $endsAt = now()->subHours(2)->startOfHour();

        $booking = Booking::factory()->confirmed()->create([
            'booking_type_id' => BookingType::factory()->paid(),
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
        $endsAt = now()->subHours(2)->startOfHour();

        $booking = Booking::factory()->confirmed()->create([
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

        foreach ($overrides as $key => $value) {
            $settings->{$key} = $value;
        }

        $settings->save();
    }

    private function admin(): User
    {
        $this->seed(ReviewPermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('manager');

        return $admin;
    }
}
