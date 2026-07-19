<?php

declare(strict_types=1);

namespace Tests\Feature\Reviews;

use App\Booking\Enums\BookingPaymentStatus;
use App\Enums\StudentStatus;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\Enums\LessonOutcome;
use App\Models\Activity;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Lesson;
use App\Models\LessonReview;
use App\Models\LessonReviewEligibility;
use App\Models\ReviewTag;
use App\Models\User;
use App\Notifications\Reviews\ReviewPublishedInstructorNotification;
use App\Notifications\Reviews\ReviewPublishedStudentNotification;
use App\Notifications\Reviews\ReviewSubmittedNotification;
use App\Reviews\Contracts\StudentReviewServiceInterface;
use App\Reviews\DTOs\SubmitStudentReviewData;
use App\Reviews\Enums\LessonReviewEligibilityStatus;
use App\Reviews\Enums\StudentReviewStatus;
use App\Reviews\Exceptions\ReviewEligibilityException;
use App\Reviews\Exceptions\ReviewValidationException;
use App\Settings\ReviewSettings;
use Database\Seeders\LessonPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 17I — student review submission: public/private submission
 * behavior, eligibility revalidation, rating/text/tag validation,
 * contact-leakage sanitization, atomicity, authorization, and the
 * guarantee that nothing publishes, aggregates, or side-affects
 * anything outside the review table.
 */
class StudentReviewSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private LessonLifecycleServiceInterface $lifecycle;

    private LessonOutcomeServiceInterface $outcomes;

    private StudentReviewServiceInterface $reviews;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lifecycle = app(LessonLifecycleServiceInterface::class);
        $this->outcomes = app(LessonOutcomeServiceInterface::class);
        $this->reviews = app(StudentReviewServiceInterface::class);

        $this->enableReviews();
    }

    // ── 1–3. Public/private submission behaviour ─────────────────────

    public function test_eligible_paid_lesson_accepts_one_public_review_submission(): void
    {
        $eligibility = $this->openEligibility($this->paidLesson());

        $result = $this->reviews->submit($eligibility, $eligibility->student, $this->data(overall: 5, content: 'Wonderful, patient teacher who explained everything clearly.'));

        $this->assertTrue($result->applied);
        $this->assertSame(StudentReviewStatus::Submitted, $result->review->status);
        $this->assertSame($eligibility->id, $result->review->eligibility_id);
        $this->assertSame($eligibility->lesson_id, $result->review->lesson_id);
        $this->assertSame($eligibility->booking_id, $result->review->booking_id);
        $this->assertSame($eligibility->student_id, $result->review->student_id);
        $this->assertSame($eligibility->instructor_id, $result->review->instructor_id);
        $this->assertSame(1, $result->review->version);
        $this->assertNotNull($result->review->submitted_at);

        $eligibility->refresh();
        $this->assertSame(LessonReviewEligibilityStatus::Used, $eligibility->status);
        $this->assertNotNull($eligibility->used_at);
        $this->assertSame(2, $eligibility->version);
    }

    public function test_private_demo_eligibility_creates_private_feedback(): void
    {
        $this->enableReviews(['demo_review_policy' => 'private_only']);
        $eligibility = $this->openEligibility($this->demoLesson());

        $result = $this->reviews->submit($eligibility, $eligibility->student, $this->data(overall: 4, content: 'Helpful trial session, would consider booking again.'));

        $this->assertSame(StudentReviewStatus::Private, $result->review->status);
        $this->assertTrue($result->review->isPrivate());
    }

    public function test_public_demo_eligibility_creates_submitted_public_review_candidate(): void
    {
        $this->enableReviews(['demo_review_policy' => 'public']);
        $eligibility = $this->openEligibility($this->demoLesson());

        $result = $this->reviews->submit($eligibility, $eligibility->student, $this->data(overall: 5, content: 'Excellent introductory lesson, very clear.'));

        $this->assertSame(StudentReviewStatus::Submitted, $result->review->status);
        $this->assertFalse($result->review->isPrivate());
    }

    // ── 4–6. Eligibility revalidation ────────────────────────────────

    public function test_expired_eligibility_rejects_submission(): void
    {
        $eligibility = $this->openEligibility($this->paidLesson());
        $eligibility->forceFill(['expires_at' => now()->subDay()])->save();

        $this->expectException(ReviewEligibilityException::class);

        $this->reviews->submit($eligibility, $eligibility->student, $this->data());
    }

    public function test_revoked_eligibility_rejects_submission(): void
    {
        $lesson = $this->paidLesson();
        $eligibility = $this->openEligibility($lesson);
        $this->outcomes->override($lesson->refresh(), $this->admin(), LessonOutcome::Cancelled, 'Cancelled after the fact.');

        $this->assertSame(LessonReviewEligibilityStatus::Revoked, $eligibility->refresh()->status);

        $this->expectException(ReviewEligibilityException::class);

        $this->reviews->submit($eligibility, $eligibility->student, $this->data());
    }

    public function test_used_eligibility_rejects_duplicate_submission(): void
    {
        $eligibility = $this->openEligibility($this->paidLesson());
        $student = $eligibility->student;

        $first = $this->reviews->submit($eligibility, $student, $this->data(overall: 5, content: 'Really great first lesson experience.'));
        $second = $this->reviews->submit($eligibility->refresh(), $student, $this->data(overall: 1, content: 'A completely different second attempt at content.'));

        $this->assertTrue($first->applied);
        $this->assertFalse($second->applied);
        $this->assertSame($first->review->id, $second->review->id);
        // The second attempt's differing data never overwrote the first.
        $this->assertSame(5, $second->review->overall_rating);
        $this->assertSame(1, LessonReview::query()->where('eligibility_id', $eligibility->id)->count());
    }

    // ── 7–8. Authorization ────────────────────────────────────────────

    public function test_another_student_cannot_submit(): void
    {
        $eligibility = $this->openEligibility($this->paidLesson());
        $stranger = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        $this->reviews->submit($eligibility, $stranger, $this->data());
    }

    public function test_instructor_cannot_submit_on_the_students_behalf(): void
    {
        $eligibility = $this->openEligibility($this->paidLesson());

        $this->expectException(AuthorizationException::class);

        $this->reviews->submit($eligibility, $eligibility->instructor, $this->data());
    }

    // ── 9–11. Rating validation ───────────────────────────────────────

    public function test_overall_rating_is_required(): void
    {
        // Enforced at the API surface itself: SubmitStudentReviewData
        // has no default and no nullable type for overallRating, so a
        // caller cannot construct a submission without one at all.
        $parameter = (new ReflectionClass(SubmitStudentReviewData::class))
            ->getConstructor()
            ->getParameters()[0];

        $this->assertSame('overallRating', $parameter->getName());
        $this->assertFalse($parameter->isDefaultValueAvailable());
        $this->assertFalse($parameter->getType()?->allowsNull());
    }

    public function test_out_of_range_low_rating_is_rejected(): void
    {
        $eligibility = $this->openEligibility($this->paidLesson());

        $this->expectException(ReviewValidationException::class);

        $this->reviews->submit($eligibility, $eligibility->student, $this->data(overall: 0));
    }

    public function test_out_of_range_high_rating_is_rejected(): void
    {
        $eligibility = $this->openEligibility($this->paidLesson());

        $this->expectException(ReviewValidationException::class);

        $this->reviews->submit($eligibility, $eligibility->student, $this->data(overall: 6));
    }

    public function test_dimension_ratings_are_validated(): void
    {
        $eligibility = $this->openEligibility($this->paidLesson());

        try {
            $this->reviews->submit($eligibility, $eligibility->student, new SubmitStudentReviewData(overallRating: 5, teachingQualityRating: 9));
            $this->fail('Expected an out-of-range dimension rating to be rejected.');
        } catch (ReviewValidationException) {
            // expected
        }

        $this->enableReviews(['rating_dimensions_enabled' => false]);
        $eligibility->refresh();

        $this->expectException(ReviewValidationException::class);
        $this->reviews->submit($eligibility, $eligibility->student, new SubmitStudentReviewData(overallRating: 5, teachingQualityRating: 4));
    }

    // ── 12. Written text length ───────────────────────────────────────

    public function test_written_text_minimum_and_maximum_are_enforced(): void
    {
        $eligibility = $this->openEligibility($this->paidLesson());

        try {
            $this->reviews->submit($eligibility, $eligibility->student, $this->data(content: 'short'));
            $this->fail('Expected text below the minimum length to be rejected.');
        } catch (ReviewValidationException) {
            // expected
        }

        $eligibility->refresh();
        $this->expectException(ReviewValidationException::class);
        $this->reviews->submit($eligibility, $eligibility->student, $this->data(content: str_repeat('x', 2001)));
    }

    // ── 13–14. Contact leakage & logging safety ──────────────────────

    public function test_contact_details_are_redacted_and_the_review_is_flagged(): void
    {
        $eligibility = $this->openEligibility($this->paidLesson());
        $email = 'reach.me.directly@example.com';

        $result = $this->reviews->submit($eligibility, $eligibility->student, $this->data(
            content: "Great lesson! Contact me at {$email} for more sessions please.",
        ));

        $this->assertSame(StudentReviewStatus::Flagged, $result->review->status);
        $this->assertStringNotContainsString($email, (string) $result->review->content);
        $this->assertStringContainsString('[redacted]', (string) $result->review->content);
        $this->assertContains('email', $result->review->sanitization_metadata['flags']);
        $this->assertTrue($result->review->sanitization_metadata['had_unsafe_content']);
    }

    public function test_unsafe_html_and_scripts_are_stripped_and_flagged(): void
    {
        $eligibility = $this->openEligibility($this->paidLesson());

        $result = $this->reviews->submit($eligibility, $eligibility->student, $this->data(
            content: '<script>alert(document.cookie)</script>Solid lesson <b onclick="steal()">overall</b>, thanks!',
        ));

        $this->assertSame(StudentReviewStatus::Flagged, $result->review->status);
        $this->assertStringNotContainsString('<script>', (string) $result->review->content);
        $this->assertStringNotContainsString('alert(', (string) $result->review->content);
        $this->assertStringNotContainsString('<b', (string) $result->review->content);
        $this->assertStringNotContainsString('onclick', (string) $result->review->content);
        $this->assertStringContainsString('Solid lesson', (string) $result->review->content);
        $this->assertContains('unsupported_html', $result->review->sanitization_metadata['flags']);
    }

    public function test_raw_unsafe_content_is_not_logged(): void
    {
        $eligibility = $this->openEligibility($this->paidLesson());
        $email = 'super.secret.address@example.com';
        $phone = '+1 (555) 987-6543';

        $this->reviews->submit($eligibility, $eligibility->student, $this->data(
            content: "Reach me at {$email} or call {$phone} to arrange private lessons.",
        ));

        $activity = Activity::query()
            ->where('log_name', 'reviews')
            ->where('event', 'student_review_submitted')
            ->latest('id')
            ->firstOrFail();

        $serialized = json_encode($activity->properties).$activity->description;
        $this->assertStringNotContainsString($email, $serialized);
        $this->assertStringNotContainsString('987-6543', $serialized);
        $this->assertContains('email', $activity->properties->get('content_flags'));
        $this->assertContains('phone_number', $activity->properties->get('content_flags'));
    }

    // ── 15–16. Tags ────────────────────────────────────────────────────

    public function test_only_configured_active_tags_are_accepted(): void
    {
        $eligibility = $this->openEligibility($this->paidLesson());
        $active = ReviewTag::factory()->create(['key' => 'patient_tutor']);
        $inactive = ReviewTag::factory()->inactive()->create(['key' => 'inactive_tag']);

        $result = $this->reviews->submit($eligibility, $eligibility->student, $this->data(tags: [$active->key]));
        $this->assertSame([['key' => 'patient_tutor', 'label' => $active->label]], $result->review->tags);

        $otherEligibility = $this->openEligibility($this->paidLesson());
        $this->expectException(ReviewValidationException::class);
        $this->reviews->submit($otherEligibility, $otherEligibility->student, $this->data(tags: [$inactive->key]));
    }

    public function test_duplicate_tags_are_normalized(): void
    {
        $tag = ReviewTag::factory()->create(['key' => 'well_prepared']);
        $eligibility = $this->openEligibility($this->paidLesson());

        $result = $this->reviews->submit($eligibility, $eligibility->student, $this->data(tags: [$tag->key, $tag->key, $tag->key]));

        $this->assertCount(1, $result->review->tags);
    }

    // ── 17–18. Atomicity & concurrency ────────────────────────────────

    public function test_submission_and_eligibility_used_transition_are_atomic(): void
    {
        $tag = ReviewTag::factory()->inactive()->create(['key' => 'bad_tag']);
        $eligibility = $this->openEligibility($this->paidLesson());

        try {
            $this->reviews->submit($eligibility, $eligibility->student, $this->data(tags: [$tag->key]));
            $this->fail('Expected the invalid tag to reject the submission.');
        } catch (ReviewValidationException) {
            // expected
        }

        // The failed validation rolled back the WHOLE transaction —
        // eligibility never partially transitioned.
        $this->assertSame(LessonReviewEligibilityStatus::Open, $eligibility->refresh()->status);
        $this->assertSame(1, $eligibility->version);
        $this->assertSame(0, LessonReview::query()->where('eligibility_id', $eligibility->id)->count());
    }

    public function test_concurrent_submissions_create_one_review(): void
    {
        $eligibility = $this->openEligibility($this->paidLesson());
        $student = $eligibility->student;

        // Two stale copies race to submit against the same eligibility.
        $copyA = LessonReviewEligibility::query()->findOrFail($eligibility->id);
        $copyB = LessonReviewEligibility::query()->findOrFail($eligibility->id);

        $first = $this->reviews->submit($copyA, $student, $this->data());
        $second = $this->reviews->submit($copyB, $student, $this->data());

        $this->assertTrue($first->applied);
        $this->assertFalse($second->applied);
        $this->assertSame(1, LessonReview::query()->where('eligibility_id', $eligibility->id)->count());
    }

    // ── 19–21. No publication, aggregation, or side effects ──────────

    public function test_public_candidate_remains_unpublished(): void
    {
        $eligibility = $this->openEligibility($this->paidLesson());

        $result = $this->reviews->submit($eligibility, $eligibility->student, $this->data());

        // Submitted is the terminal state Phase 17I ever produces for a
        // clean public candidate — nothing promotes it further.
        $this->assertSame(StudentReviewStatus::Submitted, $result->review->status);
        $this->assertFalse($result->review->status->isPubliclyVisible());
    }

    public function test_private_feedback_cannot_affect_public_rating_data(): void
    {
        $this->enableReviews(['demo_review_policy' => 'private_only']);
        $eligibility = $this->openEligibility($this->demoLesson());

        $result = $this->reviews->submit($eligibility, $eligibility->student, $this->data());

        $this->assertSame(StudentReviewStatus::Private, $result->review->status);
        $this->assertFalse($result->review->status->isPubliclyVisible());

        foreach (['instructor_review_aggregates', 'instructor_ratings'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
    }

    public function test_no_rating_aggregate_is_created(): void
    {
        $eligibility = $this->openEligibility($this->paidLesson());
        $this->reviews->submit($eligibility, $eligibility->student, $this->data());

        foreach (['instructor_review_aggregates', 'instructor_ratings', 'review_aggregates'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Phase 17I must not create a {$table} table.");
        }

        $this->assertDatabaseCount('lesson_reviews', 1);
    }

    public function test_no_notification_is_sent(): void
    {
        // Faked only around the submission itself — lesson finalization
        // upstream legitimately fires the pre-existing booking-completion
        // notification, which is unrelated to this phase. Phase 17S later
        // attaches submission/publication notifications to this exact
        // flow — those are the expected exceptions asserted below; the
        // point of this test (at the time it was written) was that
        // Phase 17I itself introduces no *other* notification pipeline.
        $eligibility = $this->openEligibility($this->paidLesson());
        Notification::fake();

        $result = $this->reviews->submit($eligibility, $eligibility->student, $this->data());

        Notification::assertSentTo($eligibility->student, ReviewSubmittedNotification::class);
        Notification::assertSentTo($eligibility->student, ReviewPublishedStudentNotification::class);
        Notification::assertSentTo($result->review->instructor, ReviewPublishedInstructorNotification::class);
    }

    public function test_no_financial_booking_outcome_or_earning_record_changes(): void
    {
        $lesson = $this->paidLesson();
        $eligibility = $this->openEligibility($lesson);
        $originalOutcome = $lesson->refresh()->outcome;
        $originalBookingStatus = $lesson->booking->status;

        $this->reviews->submit($eligibility, $eligibility->student, $this->data());

        $this->assertSame($originalOutcome, $lesson->refresh()->outcome);
        $this->assertSame($originalBookingStatus, $lesson->booking->refresh()->status);
        $this->assertSame(0, DB::table('wallets')->count());
        $this->assertSame(0, DB::table('wallet_ledger_entries')->count());
        $this->assertSame(0, DB::table('instructor_earnings')->count());
        $this->assertSame(0, DB::table('lesson_financial_dispositions')->count());
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /** Phase 24H.2: review submission requires an Active student — booking-factory students start role-less with a null status. */
    private function promoteToActiveStudent(User $student): void
    {
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $student->assignRole('student');
        $student->profile()->update(['student_status' => StudentStatus::Active]);
    }

    private function paidLesson(): Lesson
    {
        $endsAt = now()->subHours(2)->startOfHour();

        $booking = Booking::factory()->confirmed()->create([
            'booking_type_id' => BookingType::factory()->paid(),
            'starts_at' => $endsAt->copy()->subMinutes(60),
            'ends_at' => $endsAt,
            'payment_status' => BookingPaymentStatus::Paid,
            'price' => '499.00',
            'currency' => 'INR',
        ]);

        $this->promoteToActiveStudent($booking->student);

        return $this->lifecycle->createFromBooking($booking);
    }

    private function demoLesson(): Lesson
    {
        $endsAt = now()->subHours(2)->startOfHour();

        $booking = Booking::factory()->confirmed()->create([
            'starts_at' => $endsAt->copy()->subMinutes(60),
            'ends_at' => $endsAt,
            'payment_status' => BookingPaymentStatus::NotRequired,
            'price' => null,
            'currency' => null,
        ]);

        $this->promoteToActiveStudent($booking->student);

        return $this->lifecycle->createFromBooking($booking);
    }

    private function openEligibility(Lesson $lesson): LessonReviewEligibility
    {
        $this->outcomes->finalize($lesson->refresh(), LessonOutcome::Completed);

        return LessonReviewEligibility::query()->where('lesson_id', $lesson->id)->firstOrFail();
    }

    private function data(int $overall = 5, ?string $content = 'A genuinely helpful and well-structured lesson overall.', array $tags = []): SubmitStudentReviewData
    {
        return new SubmitStudentReviewData(overallRating: $overall, content: $content, tagKeys: $tags);
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

        foreach ($overrides as $key => $value) {
            $settings->{$key} = $value;
        }

        $settings->save();
    }

    private function admin(): User
    {
        $this->seed(LessonPermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('manager');

        return $admin;
    }
}
