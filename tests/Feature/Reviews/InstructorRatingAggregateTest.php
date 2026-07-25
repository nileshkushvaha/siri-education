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
use App\Models\InstructorRatingAggregate;
use App\Models\Lesson;
use App\Models\LessonReview;
use App\Models\LessonReviewEligibility;
use App\Models\ReviewRatingContribution;
use App\Models\User;
use App\Notifications\Reviews\ReviewHiddenNotification;
use App\Reviews\Contracts\InstructorRatingAggregateServiceInterface;
use App\Reviews\Contracts\ReviewModerationServiceInterface;
use App\Reviews\Contracts\StudentReviewServiceInterface;
use App\Reviews\DTOs\SubmitStudentReviewData;
use App\Reviews\Enums\StudentReviewStatus;
use App\Settings\ReviewSettings;
use Database\Seeders\ReviewPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 17K — instructor rating aggregate foundation: event-driven
 * reconciliation (add/remove/restore, idempotency, out-of-order
 * convergence), average/distribution/dimension calculation, paid vs
 * demo separation, the read-only summary DTO, the rebuild repair
 * tool, and isolation from every other financial/lesson/notification
 * record.
 */
class InstructorRatingAggregateTest extends TestCase
{
    use RefreshDatabase;

    private LessonLifecycleServiceInterface $lifecycle;

    private LessonOutcomeServiceInterface $outcomes;

    private StudentReviewServiceInterface $submissions;

    private ReviewModerationServiceInterface $moderation;

    private InstructorRatingAggregateServiceInterface $ratings;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $this->lifecycle = app(LessonLifecycleServiceInterface::class);
        $this->outcomes = app(LessonOutcomeServiceInterface::class);
        $this->submissions = app(StudentReviewServiceInterface::class);
        $this->moderation = app(ReviewModerationServiceInterface::class);
        $this->ratings = app(InstructorRatingAggregateServiceInterface::class);

        $this->enableReviews();
    }

    // ── 1. Single contribution add ─────────────────────────────────────

    public function test_published_review_creates_contribution_and_adds_to_aggregate(): void
    {
        $review = $this->submitPublicReview(overallRating: 5)->fresh();

        $aggregate = $this->aggregateFor($review->instructor_id);
        $this->assertSame(1, $aggregate->eligible_review_count);
        $this->assertSame(5, $aggregate->overall_rating_sum);
        $this->assertSame(5.0, $aggregate->overallAverage());

        $contribution = $this->contributionFor($review);
        $this->assertTrue($contribution->included);
        $this->assertSame(5, $contribution->overall_rating);
        $this->assertNotNull($contribution->applied_at);
        $this->assertNull($contribution->removed_at);
    }

    // ── 2–6. Non-contributing statuses ───────────────────────────────────

    public function test_submitted_review_does_not_contribute(): void
    {
        $this->enableReviews(['moderation_model' => 'pre_moderation']);
        $review = $this->submitPublicReview()->fresh();

        $this->assertSame(StudentReviewStatus::Submitted, $review->status);
        $this->assertNull($this->aggregateFor($review->instructor_id));
    }

    public function test_flagged_review_does_not_contribute(): void
    {
        $review = $this->submitPublicReview(content: 'Contact me at leaky@example.com for more.')->fresh();

        $this->assertSame(StudentReviewStatus::Flagged, $review->status);
        $this->assertNull($this->aggregateFor($review->instructor_id));
    }

    public function test_private_feedback_never_contributes(): void
    {
        $review = $this->submitPrivateFeedback()->fresh();

        $this->assertSame(StudentReviewStatus::Private, $review->status);
        $this->assertNull($this->aggregateFor($review->instructor_id));
    }

    public function test_rejected_review_never_contributes(): void
    {
        $this->enableReviews(['moderation_model' => 'pre_moderation']);
        $review = $this->submitPublicReview()->fresh();
        $admin = $this->admin();

        $this->moderation->reject($review, $admin, 'Not suitable for publication.');

        $this->assertNull($this->aggregateFor($review->instructor_id));
    }

    public function test_archived_review_is_removed_only_if_previously_contributing(): void
    {
        $review = $this->submitPublicReview()->fresh(); // Published, auto-published under risk_based
        $admin = $this->admin();

        $this->assertSame(1, $this->aggregateFor($review->instructor_id)->eligible_review_count);

        // Archive requires the Hidden or Private route — hide removes the
        // contribution first, so the subsequent archive is a no-op remove.
        $hidden = $this->moderation->hide($review, $admin, 'Pending cleanup.');
        $this->assertSame(0, $this->aggregateFor($review->instructor_id)->eligible_review_count);

        $this->moderation->archive($hidden->fresh(), $admin, 'No longer relevant.');

        $aggregate = $this->aggregateFor($review->instructor_id);
        $this->assertSame(0, $aggregate->eligible_review_count);
        $this->assertFalse($this->contributionFor($review->fresh())->included);
    }

    // ── 7. Hidden removal ───────────────────────────────────────────────

    public function test_hidden_review_is_removed_from_aggregate(): void
    {
        $review = $this->submitPublicReview(overallRating: 4)->fresh();
        $admin = $this->admin();

        $this->moderation->hide($review, $admin, 'Contains a borderline claim.');

        $aggregate = $this->aggregateFor($review->instructor_id);
        $this->assertSame(0, $aggregate->eligible_review_count);
        $this->assertSame(0, $aggregate->overall_rating_sum);
        $this->assertSame([], $aggregate->distribution());

        $contribution = $this->contributionFor($review->fresh());
        $this->assertFalse($contribution->included);
        $this->assertNotNull($contribution->removed_at);
    }

    // ── 8. Restored re-addition exactly once ─────────────────────────────

    public function test_restored_review_is_added_back_exactly_once(): void
    {
        $review = $this->submitPublicReview(overallRating: 3)->fresh();
        $admin = $this->admin();

        $hidden = $this->moderation->hide($review, $admin, 'Temporary hold.');
        $this->moderation->restore($hidden->fresh(), $admin, 'Reviewed — acceptable after all.');

        $aggregate = $this->aggregateFor($review->instructor_id);
        $this->assertSame(1, $aggregate->eligible_review_count);
        $this->assertSame(3, $aggregate->overall_rating_sum);

        $contribution = $this->contributionFor($review->fresh());
        $this->assertTrue($contribution->included);
        $this->assertNull($contribution->removed_at);
    }

    // ── 9–10. Duplicate events do not double-count ───────────────────────

    public function test_duplicate_published_reconciliation_does_not_double_count(): void
    {
        $review = $this->submitPublicReview()->fresh(); // already published + reconciled once

        $before = $this->aggregateFor($review->instructor_id);
        $beforeVersion = $before->version;

        $this->ratings->reconcile($review->fresh()); // simulates a duplicate delivery

        $after = $this->aggregateFor($review->instructor_id)->fresh();
        $this->assertSame(1, $after->eligible_review_count);
        $this->assertSame($beforeVersion, $after->version); // no-op — never touched
    }

    public function test_duplicate_hidden_reconciliation_does_not_double_remove(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $admin = $this->admin();
        $hidden = $this->moderation->hide($review, $admin, 'Routine check.')->fresh();

        $afterHide = $this->aggregateFor($review->instructor_id)->fresh();
        $versionAfterHide = $afterHide->version;

        $this->ratings->reconcile($hidden); // duplicate hidden delivery

        $afterDuplicate = $this->aggregateFor($review->instructor_id)->fresh();
        $this->assertSame(0, $afterDuplicate->eligible_review_count);
        $this->assertSame($versionAfterHide, $afterDuplicate->version);
    }

    // ── 11. Out-of-order convergence ────────────────────────────────────

    public function test_stale_review_snapshot_still_converges_to_current_status(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $staleCopy = LessonReview::query()->findOrFail($review->id); // captured while Published

        $this->moderation->hide($review->fresh(), $this->admin(), 'Now hidden.');
        $this->assertSame(0, $this->aggregateFor($review->instructor_id)->eligible_review_count);

        // Reconciling with the stale (Published-looking) in-memory copy
        // must still converge to "removed" because the action reloads
        // and locks the review's current DB state — never trusting the
        // caller's copy.
        $this->ratings->reconcile($staleCopy);

        $aggregate = $this->aggregateFor($review->instructor_id)->fresh();
        $this->assertSame(0, $aggregate->eligible_review_count);
        $this->assertFalse($this->contributionFor($review->fresh())->included);
    }

    // ── 12–13. Average and distribution correctness ───────────────────────

    public function test_overall_average_is_computed_from_sum_and_count(): void
    {
        $instructor = User::factory()->create();
        $this->submitPublicReview(overallRating: 5, instructor: $instructor);
        $this->submitPublicReview(overallRating: 4, instructor: $instructor);
        $this->submitPublicReview(overallRating: 3, instructor: $instructor);

        $aggregate = $this->aggregateFor($instructor->id);
        $this->assertSame(3, $aggregate->eligible_review_count);
        $this->assertSame(12, $aggregate->overall_rating_sum);
        $this->assertSame(4.0, $aggregate->overallAverage());
    }

    public function test_rating_distribution_totals_equal_eligible_review_count(): void
    {
        $instructor = User::factory()->create();
        $this->submitPublicReview(overallRating: 5, instructor: $instructor);
        $this->submitPublicReview(overallRating: 5, instructor: $instructor);
        $this->submitPublicReview(overallRating: 3, instructor: $instructor);

        $aggregate = $this->aggregateFor($instructor->id);
        $distribution = $aggregate->distribution();

        $this->assertSame(2, $distribution['5']);
        $this->assertSame(1, $distribution['3']);
        $this->assertSame($aggregate->eligible_review_count, array_sum($distribution));
    }

    // ── 14–15. Dimension ratings ─────────────────────────────────────────

    public function test_missing_dimension_ratings_are_excluded_from_dimension_average(): void
    {
        $instructor = User::factory()->create();
        $this->submitPublicReview(overallRating: 5, instructor: $instructor, teachingQuality: 5);
        $this->submitPublicReview(overallRating: 4, instructor: $instructor, teachingQuality: null);

        $aggregate = $this->aggregateFor($instructor->id);
        $this->assertSame(1, $aggregate->teaching_quality_rating_count);
        $this->assertSame(5.0, $aggregate->teachingQualityAverage());
    }

    public function test_dimension_average_correctness(): void
    {
        $instructor = User::factory()->create();
        $this->submitPublicReview(overallRating: 5, instructor: $instructor, teachingQuality: 5, communication: 4);
        $this->submitPublicReview(overallRating: 4, instructor: $instructor, teachingQuality: 3, communication: 4);

        $aggregate = $this->aggregateFor($instructor->id);
        $this->assertSame(4.0, $aggregate->teachingQualityAverage());
        $this->assertSame(4.0, $aggregate->communicationAverage());
        $this->assertNull($aggregate->punctualityAverage());
    }

    // ── 16. Paid vs demo counted separately ──────────────────────────────

    public function test_paid_and_demo_reviews_are_counted_separately(): void
    {
        $this->enableReviews(['demo_review_policy' => 'public']);
        $instructor = User::factory()->create();

        $this->submitPublicReview(overallRating: 5, instructor: $instructor);
        $this->submitPublicDemoReview(overallRating: 4, instructor: $instructor);

        $aggregate = $this->aggregateFor($instructor->id);
        $this->assertSame(1, $aggregate->paid_review_count);
        $this->assertSame(1, $aggregate->demo_review_count);
        $this->assertSame(2, $aggregate->eligible_review_count);
    }

    // ── 17. Zero-review instructor ───────────────────────────────────────

    public function test_zero_review_instructor_has_null_average_and_empty_distribution(): void
    {
        $instructor = User::factory()->create();

        $summary = $this->ratings->summaryFor($instructor->id);

        $this->assertSame(0, $summary->reviewCount);
        $this->assertNull($summary->averageRating);
        $this->assertSame([], $summary->ratingDistribution);
    }

    // ── 18. Historical rating-scale snapshot preserved ────────────────────

    public function test_historical_rating_scale_snapshot_is_preserved_after_settings_change(): void
    {
        $review = $this->submitPublicReview(overallRating: 5)->fresh();
        $this->assertNotNull($this->aggregateFor($review->instructor_id));

        // Narrow the live rating scale after submission — the review's own
        // stored snapshot must still govern its validity.
        $this->enableReviews(['rating_min' => 1, 'rating_max' => 3]);

        $this->ratings->reconcile($review->fresh()); // re-run reconciliation under the new live settings

        $aggregate = $this->aggregateFor($review->instructor_id)->fresh();
        $this->assertSame(1, $aggregate->eligible_review_count); // still included — its own snapshot said max 5
    }

    // ── 19. Concurrent-style consistency ─────────────────────────────────

    public function test_publish_hide_restore_sequence_leaves_a_consistent_aggregate(): void
    {
        $review = $this->submitPublicReview(overallRating: 4)->fresh();
        $admin = $this->admin();

        $hidden = $this->moderation->hide($review, $admin, 'Hold.');
        $restored = $this->moderation->restore($hidden->fresh(), $admin, 'Cleared.');
        $this->moderation->hide($restored->fresh(), $admin, 'Hold again.');

        $aggregate = $this->aggregateFor($review->instructor_id)->fresh();
        $this->assertSame(0, $aggregate->eligible_review_count);
        $this->assertSame(0, $aggregate->overall_rating_sum);
        $this->assertSame([], $aggregate->distribution());
    }

    // ── 20–22. Rebuild ────────────────────────────────────────────────────

    public function test_rebuild_reproduces_the_same_aggregate_as_incremental_reconciliation(): void
    {
        $instructor = User::factory()->create();
        $this->submitPublicReview(overallRating: 5, instructor: $instructor, teachingQuality: 5);
        $this->submitPublicReview(overallRating: 3, instructor: $instructor, teachingQuality: 4);

        $before = $this->aggregateFor($instructor->id)->only([
            'eligible_review_count', 'overall_rating_sum', 'rating_distribution',
            'teaching_quality_rating_sum', 'teaching_quality_rating_count',
        ]);

        $this->ratings->rebuildForInstructor($instructor->id);

        $after = $this->aggregateFor($instructor->id)->fresh()->only([
            'eligible_review_count', 'overall_rating_sum', 'rating_distribution',
            'teaching_quality_rating_sum', 'teaching_quality_rating_count',
        ]);

        $this->assertSame($before, $after);
    }

    public function test_rebuild_repairs_drifted_aggregate_sums_and_counts(): void
    {
        $review = $this->submitPublicReview(overallRating: 5)->fresh();
        $aggregate = $this->aggregateFor($review->instructor_id);

        // Simulate drift by writing directly through the authorized-mutation
        // escape hatch (bypassing normal reconciliation), as a corrupted
        // row might look after a bug or manual data fix.
        InstructorRatingAggregate::withAuthorizedMutation(function () use ($aggregate): void {
            $aggregate->fill(['eligible_review_count' => 99, 'overall_rating_sum' => 999])->save();
        });

        $rebuilt = $this->ratings->rebuildForInstructor($review->instructor_id);

        $this->assertSame(1, $rebuilt->eligible_review_count);
        $this->assertSame(5, $rebuilt->overall_rating_sum);
        $this->assertNotNull($rebuilt->rebuilt_at);

        $drift = Activity::query()
            ->where('log_name', 'reviews')
            ->where('event', 'rating_aggregate_rebuilt')
            ->latest('id')
            ->firstOrFail();
        $this->assertTrue($drift->properties->get('drifted'));
    }

    public function test_rebuild_is_idempotent(): void
    {
        $instructor = User::factory()->create();
        $this->submitPublicReview(overallRating: 4, instructor: $instructor);
        $this->submitPublicReview(overallRating: 2, instructor: $instructor);

        $first = $this->ratings->rebuildForInstructor($instructor->id);
        $firstSnapshot = $first->only(['eligible_review_count', 'overall_rating_sum', 'rating_distribution']);

        $second = $this->ratings->rebuildForInstructor($instructor->id);
        $secondSnapshot = $second->only(['eligible_review_count', 'overall_rating_sum', 'rating_distribution']);

        $this->assertSame($firstSnapshot, $secondSnapshot);
    }

    public function test_rebuild_command_runs_twice_without_changing_results(): void
    {
        $instructor = User::factory()->create();
        $this->submitPublicReview(overallRating: 5, instructor: $instructor);

        Artisan::call('reviews:rebuild-aggregates');
        $first = $this->aggregateFor($instructor->id)->fresh()->overall_rating_sum;

        Artisan::call('reviews:rebuild-aggregates');
        $second = $this->aggregateFor($instructor->id)->fresh()->overall_rating_sum;

        $this->assertSame($first, $second);
        $this->assertSame(5, $second);
    }

    // ── 23. Read-service privacy exclusions ──────────────────────────────

    public function test_summary_dto_exposes_no_private_data(): void
    {
        $review = $this->submitPublicReview(overallRating: 5)->fresh();

        $summary = $this->ratings->summaryFor($review->instructor_id);

        $this->assertSame($review->instructor_id, $summary->instructorId);
        $this->assertSame(1, $summary->reviewCount);
        $this->assertSame(5.0, $summary->averageRating);
        $this->assertArrayNotHasKey('student_id', get_object_vars($summary));
        $this->assertArrayNotHasKey('content', get_object_vars($summary));
        $this->assertArrayNotHasKey('moderation_reason', get_object_vars($summary));
    }

    // ── 24. No public UI / listing table created ─────────────────────────

    public function test_no_public_review_listing_table_is_created(): void
    {
        $this->submitPublicReview()->fresh();

        foreach (['instructor_review_listings', 'public_reviews', 'review_feed'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Phase 17K must not create a {$table} table.");
        }
    }

    // ── 25. No notification / ranking side effects ───────────────────────

    public function test_no_notification_or_ranking_update_occurs(): void
    {
        $review = $this->submitPublicReview()->fresh();
        $admin = $this->admin();

        // Faked only around the moderation action itself — lesson
        // finalization upstream legitimately fires the pre-existing
        // booking-completion notification, unrelated to this phase.
        // Phase 17S later attaches ReviewHiddenNotification to the
        // student for a hidden review — that is the one expected
        // exception to "no notification" here; the point of this test
        // is that hiding never touches marketplace ranking.
        Notification::fake();
        $this->moderation->hide($review, $admin, 'Routine check.');

        Notification::assertSentTo($review->student, ReviewHiddenNotification::class);
        $this->assertFalse(Schema::hasTable('instructor_marketplace_rankings'));
    }

    // ── 26. No financial / lesson record changes ──────────────────────────

    public function test_no_financial_booking_lesson_or_earning_record_changes(): void
    {
        $lesson = $this->paidLesson();
        $review = $this->submitPublicReview(lesson: $lesson)->fresh();
        $originalOutcome = $lesson->refresh()->outcome;
        $originalBookingStatus = $lesson->booking->status;

        $this->moderation->hide($review, $this->admin(), 'Routine check.');

        $this->assertSame($originalOutcome, $lesson->refresh()->outcome);
        $this->assertSame($originalBookingStatus, $lesson->booking->refresh()->status);
        $this->assertSame(0, DB::table('wallets')->count());
        $this->assertSame(0, DB::table('wallet_ledger_entries')->count());
        $this->assertSame(0, DB::table('instructor_earnings')->count());
        $this->assertSame(0, DB::table('lesson_financial_dispositions')->count());
    }

    // ── 27. Phase 17H–17J regression ──────────────────────────────────────

    public function test_review_moderation_transitions_still_work_unaffected(): void
    {
        $review = $this->submitPublicReview(content: 'DM me on @sketchy_handle for more info.')->fresh();
        $this->assertSame(StudentReviewStatus::Flagged, $review->status);

        $approved = $this->moderation->approve($review, $this->admin(), 'Reviewed manually — fine.');

        $this->assertSame(StudentReviewStatus::Published, $approved->status);
        $this->assertSame(1, $this->aggregateFor($review->instructor_id)->eligible_review_count);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function aggregateFor(int $instructorId): ?InstructorRatingAggregate
    {
        return InstructorRatingAggregate::query()->where('instructor_id', $instructorId)->first();
    }

    private function contributionFor(LessonReview $review): ?ReviewRatingContribution
    {
        return ReviewRatingContribution::query()->where('review_id', $review->id)->first();
    }

    private function paidLesson(?User $instructor = null, ?User $student = null): Lesson
    {
        $endsAt = now()->subHours(2)->startOfHour();

        $booking = Booking::factory()->confirmed()->create([
            'booking_type_id' => BookingType::factory()->paid(),
            'instructor_id' => $instructor?->id ?? User::factory(),
            'student_id' => ($student ?? User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]))->id,
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
            'instructor_id' => $instructor?->id ?? User::factory(),
            'student_id' => ($student ?? User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]))->id,
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
        ?Lesson $lesson = null,
        string $content = 'A genuinely helpful and well-structured lesson overall.',
        int $overallRating = 5,
        ?User $instructor = null,
        ?int $teachingQuality = null,
        ?int $communication = null,
    ): LessonReview {
        $eligibility = $this->openEligibility($lesson ?? $this->paidLesson($instructor));

        $result = $this->submissions->submit($eligibility, $eligibility->student, new SubmitStudentReviewData(
            overallRating: $overallRating,
            teachingQualityRating: $teachingQuality,
            communicationRating: $communication,
            content: $content,
        ));

        return $result->review;
    }

    private function submitPublicDemoReview(
        int $overallRating = 4,
        ?User $instructor = null,
        string $content = 'A genuinely helpful and well-structured demo lesson.',
    ): LessonReview {
        $eligibility = $this->openEligibility($this->demoLesson($instructor));

        $result = $this->submissions->submit($eligibility, $eligibility->student, new SubmitStudentReviewData(
            overallRating: $overallRating,
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
