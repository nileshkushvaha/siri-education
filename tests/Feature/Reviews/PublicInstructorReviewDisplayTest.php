<?php

declare(strict_types=1);

namespace Tests\Feature\Reviews;

use App\Booking\Enums\BookingPaymentStatus;
use App\Enums\InstructorStatus;
use App\Enums\StudentStatus;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\Enums\LessonOutcome;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Lesson;
use App\Models\LessonReview;
use App\Models\LessonReviewEligibility;
use App\Models\User;
use App\Reviews\Contracts\PublicInstructorReviewServiceInterface;
use App\Reviews\Contracts\ReviewModerationServiceInterface;
use App\Reviews\Contracts\StudentReviewServiceInterface;
use App\Reviews\DTOs\PublicInstructorReviewData;
use App\Reviews\DTOs\SubmitStudentReviewData;
use App\Reviews\Enums\StudentReviewStatus;
use App\Settings\ReviewSettings;
use Database\Seeders\ReviewPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 17L — public review display: inclusion/exclusion on the
 * existing instructor profile page, student-identity masking, the
 * derived (never client-controlled) Verified Lesson badge, aggregate
 * reuse, pagination/ordering, and the guarantee that no moderation
 * note, student identity field, or financial value ever reaches a
 * public response.
 */
class PublicInstructorReviewDisplayTest extends TestCase
{
    use RefreshDatabase;

    private LessonLifecycleServiceInterface $lifecycle;

    private LessonOutcomeServiceInterface $outcomes;

    private StudentReviewServiceInterface $submissions;

    private ReviewModerationServiceInterface $moderation;

    private PublicInstructorReviewServiceInterface $publicReviews;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'Update:User', 'guard_name' => 'web']);

        $this->lifecycle = app(LessonLifecycleServiceInterface::class);
        $this->outcomes = app(LessonOutcomeServiceInterface::class);
        $this->submissions = app(StudentReviewServiceInterface::class);
        $this->moderation = app(ReviewModerationServiceInterface::class);
        $this->publicReviews = app(PublicInstructorReviewServiceInterface::class);

        $this->enableReviews();
    }

    // ── 1–2. Aggregate summary on the profile page ────────────────────────

    public function test_public_profile_shows_aggregate_average(): void
    {
        $instructor = $this->makeInstructor();
        $this->submitPublicReview($instructor, overallRating: 5);
        $this->submitPublicReview($instructor, overallRating: 3);

        $this->get(route('instructors.show', $instructor))
            ->assertOk()
            ->assertSee('4.0');
    }

    public function test_public_profile_shows_eligible_review_count(): void
    {
        $instructor = $this->makeInstructor();
        $this->submitPublicReview($instructor);
        $this->submitPublicReview($instructor);

        $this->get(route('instructors.show', $instructor))
            ->assertOk()
            ->assertSee('Based on 2 reviews');
    }

    // ── 3–10. Inclusion / exclusion ─────────────────────────────────────

    public function test_published_public_review_is_displayed(): void
    {
        $instructor = $this->makeInstructor();
        $this->submitPublicReview($instructor, content: 'A genuinely excellent and thorough lesson.');

        $this->get(route('instructors.show', $instructor))
            ->assertOk()
            ->assertSee('A genuinely excellent and thorough lesson.');
    }

    public function test_private_feedback_is_never_displayed(): void
    {
        $instructor = $this->makeInstructor();
        $this->submitPrivateFeedback($instructor, content: 'Private note only the instructor should see.');

        $this->get(route('instructors.show', $instructor))
            ->assertOk()
            ->assertDontSee('Private note only the instructor should see.');
    }

    public function test_submitted_review_is_not_displayed(): void
    {
        $this->enableReviews(['moderation_model' => 'pre_moderation']);
        $instructor = $this->makeInstructor();
        $this->submitPublicReview($instructor, content: 'Still awaiting moderation entirely.');

        $this->get(route('instructors.show', $instructor))
            ->assertOk()
            ->assertDontSee('Still awaiting moderation entirely.');
    }

    public function test_flagged_review_is_not_displayed(): void
    {
        $instructor = $this->makeInstructor();
        $this->submitPublicReview($instructor, content: 'Contact me at leaky@example.com please.');

        $this->get(route('instructors.show', $instructor))
            ->assertOk()
            ->assertDontSee('Contact me at');
    }

    public function test_hidden_review_disappears(): void
    {
        $instructor = $this->makeInstructor();
        $review = $this->submitPublicReview($instructor, content: 'Visible until an admin hides it.')->fresh();

        $this->get(route('instructors.show', $instructor))->assertSee('Visible until an admin hides it.');

        $this->moderation->hide($review, $this->admin(), 'Routine check.');

        $this->get(route('instructors.show', $instructor))
            ->assertOk()
            ->assertDontSee('Visible until an admin hides it.');
    }

    public function test_rejected_review_is_not_displayed(): void
    {
        $this->enableReviews(['moderation_model' => 'pre_moderation']);
        $instructor = $this->makeInstructor();
        $review = $this->submitPublicReview($instructor, content: 'Never should have been submitted.')->fresh();

        $this->moderation->reject($review, $this->admin(), 'Not suitable for publication.');

        $this->get(route('instructors.show', $instructor))
            ->assertOk()
            ->assertDontSee('Never should have been submitted.');
    }

    public function test_archived_review_is_not_displayed(): void
    {
        $instructor = $this->makeInstructor();
        $review = $this->submitPublicReview($instructor, content: 'Archived after being hidden.')->fresh();
        $admin = $this->admin();

        $hidden = $this->moderation->hide($review, $admin, 'Pending cleanup.');
        $this->moderation->archive($hidden->fresh(), $admin, 'No longer relevant.');

        $this->get(route('instructors.show', $instructor))
            ->assertOk()
            ->assertDontSee('Archived after being hidden.');
    }

    public function test_review_belonging_to_another_instructor_is_excluded(): void
    {
        $instructorA = $this->makeInstructor();
        $instructorB = $this->makeInstructor();
        $this->submitPublicReview($instructorB, content: 'This review belongs to instructor B only.');

        $this->get(route('instructors.show', $instructorA))
            ->assertOk()
            ->assertDontSee('This review belongs to instructor B only.');
    }

    // ── 11–15. Identity masking ─────────────────────────────────────────

    public function test_student_identity_is_masked_by_default(): void
    {
        $instructor = $this->makeInstructor();
        $student = User::factory()->create(['status' => 'active', 'first_name' => 'Nilesh', 'last_name' => 'Kushvaha']);
        $this->submitPublicReview($instructor, student: $student);

        $response = $this->get(route('instructors.show', $instructor))->assertOk();
        $response->assertDontSee($student->name);
        $response->assertSee('N***');
    }

    public function test_anonymous_mode_shows_verified_student(): void
    {
        $this->enableReviews(['public_review_identity_mode' => 'anonymous']);
        $instructor = $this->makeInstructor();
        $student = User::factory()->create(['status' => 'active', 'first_name' => 'Nilesh', 'last_name' => 'Kushvaha']);
        $this->submitPublicReview($instructor, student: $student);

        $this->get(route('instructors.show', $instructor))
            ->assertOk()
            ->assertSee('Verified Student')
            ->assertDontSee('Nilesh');
    }

    public function test_first_name_only_mode_never_reveals_surname(): void
    {
        $this->enableReviews(['public_review_identity_mode' => 'first_name_only']);
        $instructor = $this->makeInstructor();
        $student = User::factory()->create(['status' => 'active', 'first_name' => 'Nilesh', 'last_name' => 'Kushvaha']);
        $this->submitPublicReview($instructor, student: $student);

        $response = $this->get(route('instructors.show', $instructor))->assertOk();
        $response->assertSee('Nilesh');
        $response->assertDontSee('Kushvaha');
    }

    public function test_email_phone_student_id_and_profile_image_are_absent(): void
    {
        $instructor = $this->makeInstructor();
        $student = User::factory()->create([
            'status' => 'active',
            'first_name' => 'Nilesh',
            'last_name' => 'Kushvaha',
            'email' => 'nilesh-private@example.com',
        ]);
        $this->submitPublicReview($instructor, student: $student);

        $response = $this->get(route('instructors.show', $instructor))->assertOk();
        $response->assertDontSee('nilesh-private@example.com');
        // Phase 18A: the review's own primary key now legitimately
        // reaches the page via the embedded `reviews.report-review`
        // Livewire component (needed so the "Report Review" action
        // targets the right review server-side) — it carries no
        // student/booking/moderation data itself, and every write path
        // re-validates against the database rather than trusting it.
        // The DTO-field test above remains the reliable guarantee that
        // no *sensitive* internal field (student id, moderation reason,
        // booking id) is ever exposed.
    }

    public function test_archived_student_falls_back_to_verified_student(): void
    {
        $this->enableReviews(['public_review_identity_mode' => 'first_name_only']);
        $instructor = $this->makeInstructor();
        $student = User::factory()->create(['status' => 'active', 'first_name' => 'Nilesh', 'last_name' => 'Kushvaha']);
        $this->submitPublicReview($instructor, student: $student);
        $student->profile->update(['student_status' => StudentStatus::Archived]);

        $response = $this->get(route('instructors.show', $instructor))->assertOk();
        $response->assertSee('Verified Student');
        $response->assertDontSee('Nilesh');
    }

    // ── 16–17. Verified Lesson badge ──────────────────────────────────────

    public function test_verified_lesson_badge_is_derived_from_the_lesson_relationship(): void
    {
        $instructor = $this->makeInstructor();
        $this->submitPublicReview($instructor, content: 'A properly completed and verified lesson.');

        $this->get(route('instructors.show', $instructor))
            ->assertOk()
            ->assertSee('Verified Lesson');
    }

    public function test_review_with_since_reversed_outcome_is_not_marked_verified(): void
    {
        $instructor = $this->makeInstructor();
        $lesson = $this->paidLesson($instructor);
        $eligibility = $this->openEligibility($lesson);
        $review = $this->submissions->submit($eligibility, $eligibility->student, new SubmitStudentReviewData(
            overallRating: 5,
            content: 'Outcome later corrected away from completed.',
        ))->review;

        $this->outcomes->override($lesson->refresh(), $this->admin(), LessonOutcome::StudentNoShow, 'Attendance evidence corrected.');

        $response = $this->get(route('instructors.show', $instructor))->assertOk();
        // The review still displays (moderation status is untouched by
        // the outcome correction) but must no longer claim verification.
        $response->assertSee('Outcome later corrected away from completed.');
        $response->assertDontSee('Verified Lesson');
        $this->assertSame(StudentReviewStatus::Published, $review->fresh()->status);
    }

    // ── 18. Aggregate service used, never recalculated ────────────────────

    public function test_displayed_average_matches_the_reused_aggregate_service(): void
    {
        $instructor = $this->makeInstructor();
        $this->submitPublicReview($instructor, overallRating: 5);
        $this->submitPublicReview($instructor, overallRating: 4);
        $this->submitPublicReview($instructor, overallRating: 3);

        $summary = $this->publicReviews->summaryFor($instructor->fresh());

        $this->get(route('instructors.show', $instructor))
            ->assertOk()
            ->assertSee(number_format($summary->averageRating, 1));
    }

    // ── 19. Zero-review state ────────────────────────────────────────────

    public function test_zero_review_instructor_shows_empty_state_not_zero(): void
    {
        $instructor = $this->makeInstructor();

        $response = $this->get(route('instructors.show', $instructor))->assertOk();
        $response->assertSee('No ratings yet');
        $response->assertSee('No reviews yet');
        $response->assertDontSee('0.0 / 5');
    }

    // ── 20–21. Ordering & pagination ──────────────────────────────────────

    public function test_reviews_are_ordered_newest_first_deterministically(): void
    {
        $instructor = $this->makeInstructor();
        $older = $this->submitPublicReview($instructor, content: 'Older review content marker.')->fresh();
        $newer = $this->submitPublicReview($instructor, content: 'Newer review content marker.')->fresh();

        $older->forceFill(['moderated_at' => now()->subDays(3)])->saveQuietly();
        $newer->forceFill(['moderated_at' => now()->subDay()])->saveQuietly();

        $body = $this->get(route('instructors.show', $instructor))->assertOk()->getContent();

        $this->assertLessThan(
            strpos($body, 'Older review content marker.'),
            strpos($body, 'Newer review content marker.'),
        );
    }

    public function test_pagination_respects_the_configured_page_size(): void
    {
        $instructor = $this->makeInstructor();

        foreach (range(1, 12) as $i) {
            $review = $this->submitPublicReview($instructor, content: "Distinct review body number {$i}.")->fresh();
            // Force explicit, strictly increasing publish times so page
            // placement is deterministic regardless of how fast the
            // loop runs (auto-publish would otherwise tie on `now()`).
            $review->forceFill(['moderated_at' => now()->subMinutes(12 - $i)])->saveQuietly();
        }

        $firstPage = $this->get(route('instructors.show', $instructor))->assertOk();
        $firstPage->assertSee('Distinct review body number 12.'); // newest of 12, page 1
        $firstPage->assertDontSee('Distinct review body number 1.', false);

        $secondPage = $this->get(route('instructors.show', ['user' => $instructor, 'page' => 2]))->assertOk();
        $secondPage->assertSee('Distinct review body number 1.');
    }

    // ── 22. No stale-cache visibility ────────────────────────────────────

    public function test_hidden_review_never_reappears_on_repeated_requests(): void
    {
        $instructor = $this->makeInstructor();
        $review = $this->submitPublicReview($instructor, content: 'Checked twice for cache staleness.')->fresh();
        $admin = $this->admin();

        $this->moderation->hide($review, $admin, 'Routine check.');

        $this->get(route('instructors.show', $instructor))->assertDontSee('Checked twice for cache staleness.');
        $this->get(route('instructors.show', $instructor))->assertDontSee('Checked twice for cache staleness.');
    }

    // ── 23. DTO field surface ────────────────────────────────────────────

    public function test_public_dto_contains_no_moderation_or_internal_fields(): void
    {
        $instructor = $this->makeInstructor();
        $submitted = $this->submitPublicReview($instructor)->fresh();

        $page = $this->publicReviews->paginatedReviewsFor($instructor->fresh());
        $review = $page->items()[0];

        $this->assertInstanceOf(PublicInstructorReviewData::class, $review);
        // Phase 18A: the review's own primary key is now intentionally
        // present — needed to target the "Report Review" action — but
        // nothing else that could identify the student, booking, or an
        // internal moderation/quality decision.
        $this->assertSame($submitted->id, $review->id);
        $fields = array_keys(get_object_vars($review));

        foreach (['studentId', 'student_id', 'moderationReason', 'moderation_reason', 'bookingId', 'booking_id', 'internalQualityScore'] as $forbidden) {
            $this->assertNotContains($forbidden, $fields);
        }
    }

    // ── 24. No financial data ────────────────────────────────────────────

    public function test_instructor_compensation_and_student_pricing_are_never_exposed(): void
    {
        $instructor = $this->makeInstructor();
        $this->submitPublicReview($instructor);

        $this->get(route('instructors.show', $instructor))
            ->assertOk()
            ->assertDontSee('499.00');
    }

    // ── 25. Existing profile actions still work ───────────────────────────

    public function test_existing_public_profile_actions_still_render(): void
    {
        $instructor = $this->makeInstructor();
        $this->submitPublicReview($instructor);

        $this->get(route('instructors.show', $instructor))
            ->assertOk()
            ->assertSee('Book a Demo Session')
            ->assertSee('Book a Paid Class')
            ->assertSee('Instructor Snapshot');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function makeInstructor(array $profileOverrides = []): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->profile->update(array_merge([
            'profile_visibility' => 'public',
            'instructor_status' => InstructorStatus::Approved,
        ], $profileOverrides));
        $user->assignRole('instructor');

        return $user;
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

    private function submitPublicReview(
        User $instructor,
        ?User $student = null,
        string $content = 'A genuinely helpful and well-structured lesson overall.',
        int $overallRating = 5,
    ): LessonReview {
        $eligibility = $this->openEligibility($this->paidLesson($instructor, $student));

        $result = $this->submissions->submit($eligibility, $eligibility->student, new SubmitStudentReviewData(
            overallRating: $overallRating,
            content: $content,
        ));

        return $result->review;
    }

    private function submitPrivateFeedback(User $instructor, string $content = 'Helpful trial session, thanks.'): LessonReview
    {
        $this->enableReviews(['demo_review_policy' => 'private_only']);
        $eligibility = $this->openEligibility($this->demoLesson($instructor));

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

        foreach ($overrides as $key => $value) {
            $settings->{$key} = $value;
        }

        $settings->save();
    }

    private function admin(): User
    {
        $this->seed(ReviewPermissionSeeder::class);

        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('super_admin');

        return $admin;
    }
}
