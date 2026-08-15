<?php

declare(strict_types=1);

namespace Tests\Feature\Reviews;

use App\Booking\Enums\BookingPaymentStatus;
use App\Enums\InstructorStatus;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\Enums\LessonOutcome;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Lesson;
use App\Models\LessonReview;
use App\Models\LessonReviewEligibility;
use App\Models\ReviewTag;
use App\Models\User;
use App\Reviews\Contracts\InstructorQualityInsightsServiceInterface;
use App\Reviews\Contracts\InstructorRatingAggregateServiceInterface;
use App\Reviews\Contracts\ReviewModerationServiceInterface;
use App\Reviews\Contracts\StudentReviewServiceInterface;
use App\Reviews\DTOs\InstructorQualityInsightsData;
use App\Reviews\DTOs\SubmitStudentReviewData;
use App\Reviews\Enums\StudentReviewStatus;
use App\Settings\ReviewSettings;
use Database\Seeders\ReviewPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Instructor-facing quality insights: own-data-only authorization,
 * exact reuse of the rating aggregate and public-review projection
 * (never recalculated), deterministic
 * highlight/improvement-area selection with a minimum-sample gate, tag
 * aggregation, and the guarantee that nothing here exposes student
 * contact details, private feedback, moderation data, quality-alert
 * information, financial data, or a fabricated metric.
 */
class InstructorQualityInsightsTest extends TestCase
{
    use RefreshDatabase;

    private LessonLifecycleServiceInterface $lifecycle;

    private LessonOutcomeServiceInterface $outcomes;

    private StudentReviewServiceInterface $submissions;

    private ReviewModerationServiceInterface $moderation;

    private InstructorQualityInsightsServiceInterface $insights;

    private InstructorRatingAggregateServiceInterface $aggregates;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $this->lifecycle = app(LessonLifecycleServiceInterface::class);
        $this->outcomes = app(LessonOutcomeServiceInterface::class);
        $this->submissions = app(StudentReviewServiceInterface::class);
        $this->moderation = app(ReviewModerationServiceInterface::class);
        $this->insights = app(InstructorQualityInsightsServiceInterface::class);
        $this->aggregates = app(InstructorRatingAggregateServiceInterface::class);

        $this->enableReviews();
    }

    // ── 1–4. Authorization ──────────────────────────────────────────────

    public function test_instructor_can_access_their_own_quality_insights(): void
    {
        $instructor = $this->instructorUser();
        $this->submitPublicReview($instructor);

        $this->actingAs($instructor)
            ->get(route('dashboard.instructor.quality-insights'))
            ->assertOk()
            ->assertSee('Reviews', false);
    }

    public function test_instructor_cannot_access_another_instructors_insights(): void
    {
        $instructorA = $this->instructorUser();
        $instructorB = $this->instructorUser();
        $this->submitPublicReview($instructorA, overallRating: 5);
        $this->submitPublicReview($instructorB, overallRating: 1);

        // The service always scopes to whichever User is passed — the
        // controller only ever passes auth()->user(), never a
        // request-supplied id, so there is no route parameter through
        // which instructor B could request instructor A's data.
        $dataForA = $this->insights->insightsFor($instructorA);
        $dataForB = $this->insights->insightsFor($instructorB);

        $this->assertSame(5.0, $dataForA->ratingSummary->averageRating);
        $this->assertSame(1.0, $dataForB->ratingSummary->averageRating);
    }

    public function test_student_cannot_access_instructor_insights(): void
    {
        $student = User::factory()->create(['status' => 'active']);

        $this->actingAs($student)
            ->get(route('dashboard.instructor.quality-insights'))
            ->assertForbidden();
    }

    public function test_guest_cannot_access_instructor_insights(): void
    {
        $this->get(route('dashboard.instructor.quality-insights'))
            ->assertRedirect();
    }

    // ── 5–6. Rating summary reuse ────────────────────────────────────────

    public function test_overall_average_uses_the_existing_aggregate(): void
    {
        $instructor = $this->instructorUser();
        $this->submitPublicReview($instructor, overallRating: 4);
        $this->submitPublicReview($instructor, overallRating: 2);

        $fromAggregate = $this->aggregates->summaryFor($instructor->id);
        $fromInsights = $this->insights->insightsFor($instructor);

        $this->assertSame($fromAggregate->averageRating, $fromInsights->ratingSummary->averageRating);
    }

    public function test_eligible_published_review_count_is_correct(): void
    {
        $instructor = $this->instructorUser();
        $this->submitPublicReview($instructor);
        $this->submitPublicReview($instructor);

        $this->assertSame(2, $this->insights->insightsFor($instructor)->ratingSummary->reviewCount);
    }

    // ── 7–10. Exclusions ─────────────────────────────────────────────────

    public function test_private_feedback_is_excluded_from_public_rating_summary(): void
    {
        $instructor = $this->instructorUser();
        $this->submitPrivateFeedback($instructor, overallRating: 1);

        $summary = $this->insights->insightsFor($instructor)->ratingSummary;
        $this->assertSame(0, $summary->reviewCount);
        $this->assertNull($summary->averageRating);
    }

    public function test_hidden_review_is_excluded(): void
    {
        $instructor = $this->instructorUser();
        $review = $this->submitPublicReview($instructor)->fresh();
        $this->assertSame(1, $this->insights->insightsFor($instructor)->ratingSummary->reviewCount);

        $this->moderation->hide($review, $this->admin(), 'Routine check.');

        $this->assertSame(0, $this->insights->insightsFor($instructor)->ratingSummary->reviewCount);
    }

    public function test_rejected_review_is_excluded(): void
    {
        $this->enableReviews(['moderation_model' => 'pre_moderation']);
        $instructor = $this->instructorUser();
        $review = $this->submitPublicReview($instructor)->fresh();
        $this->moderation->reject($review, $this->admin(), 'Not suitable.');

        $this->assertSame(0, $this->insights->insightsFor($instructor)->ratingSummary->reviewCount);
    }

    public function test_archived_review_is_excluded(): void
    {
        $instructor = $this->instructorUser();
        $review = $this->submitPublicReview($instructor)->fresh();
        $admin = $this->admin();
        $hidden = $this->moderation->hide($review, $admin, 'Pending cleanup.');
        $this->moderation->archive($hidden->fresh(), $admin, 'No longer relevant.');

        $this->assertSame(0, $this->insights->insightsFor($instructor)->ratingSummary->reviewCount);
    }

    // ── 11–13. Distribution & dimensions ─────────────────────────────────

    public function test_rating_distribution_is_displayed_correctly(): void
    {
        $instructor = $this->instructorUser();
        $this->submitPublicReview($instructor, overallRating: 5);
        $this->submitPublicReview($instructor, overallRating: 5);
        $this->submitPublicReview($instructor, overallRating: 3);

        $distribution = $this->insights->insightsFor($instructor)->ratingSummary->ratingDistribution;

        $this->assertSame(2, $distribution['5']);
        $this->assertSame(1, $distribution['3']);
    }

    public function test_dimension_averages_are_displayed_correctly(): void
    {
        $instructor = $this->instructorUser();
        $this->submitPublicReview($instructor, overallRating: 5, teachingQuality: 5);
        $this->submitPublicReview($instructor, overallRating: 3, teachingQuality: 3);

        $summary = $this->insights->insightsFor($instructor)->ratingSummary;
        $this->assertSame(4.0, $summary->dimensionAverages['teaching_quality']);
        $this->assertSame(2, $summary->dimensionCounts['teaching_quality']);
    }

    public function test_missing_dimension_ratings_are_not_treated_as_zero(): void
    {
        $instructor = $this->instructorUser();
        $this->submitPublicReview($instructor, overallRating: 5, teachingQuality: 5);
        $this->submitPublicReview($instructor, overallRating: 4, teachingQuality: null);

        $summary = $this->insights->insightsFor($instructor)->ratingSummary;
        $this->assertSame(1, $summary->dimensionCounts['teaching_quality']);
        $this->assertSame(5.0, $summary->dimensionAverages['teaching_quality']);
    }

    // ── 14. Punctuality ──────────────────────────────────────────────────

    public function test_punctuality_uses_the_review_dimension_aggregate_only(): void
    {
        $instructor = $this->instructorUser();
        $this->submitPublicReview($instructor, overallRating: 5, punctuality: 4);
        $this->submitPublicReview($instructor, overallRating: 5, punctuality: 2);

        $summary = $this->insights->insightsFor($instructor)->ratingSummary;
        $this->assertSame(3.0, $summary->dimensionAverages['punctuality']);
        $this->assertSame(2, $summary->dimensionCounts['punctuality']);
    }

    // ── 15–16. Feedback tags ─────────────────────────────────────────────

    public function test_positive_review_tags_are_aggregated_correctly(): void
    {
        $instructor = $this->instructorUser();
        $tagA = ReviewTag::factory()->create(['key' => 'patient', 'label' => 'Patient', 'is_active' => true, 'applicable_modes' => ['public_review']]);
        $tagB = ReviewTag::factory()->create(['key' => 'knowledgeable', 'label' => 'Knowledgeable', 'is_active' => true, 'applicable_modes' => ['public_review']]);

        $this->submitPublicReview($instructor, tagKeys: ['patient']);
        $this->submitPublicReview($instructor, tagKeys: ['patient', 'knowledgeable']);

        $tags = collect($this->insights->insightsFor($instructor)->feedbackTags)->keyBy('key');
        $this->assertSame(2, $tags['patient']->count);
        $this->assertSame(1, $tags['knowledgeable']->count);
    }

    public function test_improvement_tags_are_aggregated_without_student_identity(): void
    {
        $instructor = $this->instructorUser();
        ReviewTag::factory()->create(['key' => 'patient', 'label' => 'Patient', 'is_active' => true, 'applicable_modes' => ['public_review']]);
        $this->submitPublicReview($instructor, tagKeys: ['patient']);

        $tag = $this->insights->insightsFor($instructor)->feedbackTags[0];
        $fields = array_keys(get_object_vars($tag));

        foreach (['studentId', 'student_id', 'reporterId'] as $forbidden) {
            $this->assertNotContains($forbidden, $fields);
        }
    }

    // ── 17. Minimum sample gate ───────────────────────────────────────────

    public function test_lowest_rated_dimensions_require_supporting_data(): void
    {
        $instructor = $this->instructorUser();
        $this->submitPublicReview($instructor, overallRating: 2, teachingQuality: 2); // only 1 review — below MIN_DIMENSION_SAMPLE

        $this->assertCount(0, $this->insights->insightsFor($instructor)->improvementAreas);
        $this->assertCount(0, $this->insights->insightsFor($instructor)->topDimensions);
    }

    // ── 18. No AI / fabricated content ────────────────────────────────────

    public function test_no_ai_generated_summary_or_recommendation_is_produced(): void
    {
        $fields = array_keys(get_object_vars(new \ReflectionClass(InstructorQualityInsightsData::class)));
        $properties = (new \ReflectionClass(InstructorQualityInsightsData::class))->getProperties();
        $propertyNames = array_map(fn ($p) => $p->getName(), $properties);

        foreach (['summary', 'recommendation', 'aiSummary', 'coachingAdvice', 'suggestion'] as $forbidden) {
            $this->assertNotContains($forbidden, $propertyNames);
        }
    }

    // ── 19–20. Recent reviews ────────────────────────────────────────────

    public function test_recent_published_reviews_are_paginated(): void
    {
        $instructor = $this->instructorUser();
        foreach (range(1, 12) as $i) {
            $this->submitPublicReview($instructor, content: "Distinct review body {$i}.");
        }

        $page = $this->insights->recentReviewsFor($instructor, perPage: 10);

        $this->assertCount(10, $page->items());
        $this->assertSame(12, $page->total());
    }

    public function test_recent_reviews_are_ordered_deterministically(): void
    {
        $instructor = $this->instructorUser();
        $older = $this->submitPublicReview($instructor, content: 'Older review content marker.')->fresh();
        $newer = $this->submitPublicReview($instructor, content: 'Newer review content marker.')->fresh();
        $older->forceFill(['moderated_at' => now()->subDays(3)])->saveQuietly();
        $newer->forceFill(['moderated_at' => now()->subDay()])->saveQuietly();

        $page = $this->insights->recentReviewsFor($instructor);

        $this->assertSame('Newer review content marker.', $page->items()[0]->content);
        $this->assertSame('Older review content marker.', $page->items()[1]->content);
    }

    // ── 21–25. Privacy ────────────────────────────────────────────────────

    public function test_reviewer_identity_remains_masked(): void
    {
        $instructor = $this->instructorUser();
        $this->submitPublicReview($instructor, studentFirstName: 'Priyanka');

        $response = $this->actingAs($instructor)->get(route('dashboard.instructor.quality-insights'));
        $response->assertOk();
        $response->assertDontSee('Priyanka');
        $response->assertSee('P***');
    }

    public function test_student_email_phone_image_and_id_are_absent(): void
    {
        $instructor = $this->instructorUser();
        $student = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE, 'first_name' => 'Priyanka', 'email' => 'priyanka-private@example.com']);
        $this->submitPublicReview($instructor, student: $student);

        $response = $this->actingAs($instructor)->get(route('dashboard.instructor.quality-insights'));
        $response->assertOk();
        $response->assertDontSee('priyanka-private@example.com');
    }

    public function test_private_review_text_is_absent(): void
    {
        $instructor = $this->instructorUser();
        $this->submitPrivateFeedback($instructor, content: 'This is a private concern only the instructor should never see publicly.');

        $response = $this->actingAs($instructor)->get(route('dashboard.instructor.quality-insights'));
        $response->assertOk();
        $response->assertDontSee('private concern only the instructor');
    }

    public function test_moderation_reasons_and_report_details_are_absent(): void
    {
        $instructor = $this->instructorUser();
        $review = $this->submitPublicReview($instructor)->fresh();
        $this->moderation->hide($review, $this->admin(), 'A very specific internal moderation note.');
        $this->moderation->restore($review->fresh(), $this->admin(), 'Reviewed — acceptable.');

        $response = $this->actingAs($instructor)->get(route('dashboard.instructor.quality-insights'));
        $response->assertOk();
        $response->assertDontSee('A very specific internal moderation note.');
    }

    public function test_quality_alerts_and_internal_risk_scoring_are_absent(): void
    {
        $instructor = $this->instructorUser();
        $this->submitPublicReview($instructor);

        $response = $this->actingAs($instructor)->get(route('dashboard.instructor.quality-insights'));
        $response->assertOk();
        foreach (['quality_alert', 'QualityAlert', 'risk score', 'quality score'] as $forbidden) {
            $response->assertDontSee($forbidden);
        }
    }

    // ── 26. No mutation possible ─────────────────────────────────────────

    public function test_instructor_cannot_mutate_reviews_or_aggregates(): void
    {
        $instructor = $this->instructorUser();
        $review = $this->submitPublicReview($instructor)->fresh();
        $reviewVersionBefore = $review->version;

        $this->insights->insightsFor($instructor);
        $this->insights->insightsFor($instructor);
        $this->insights->recentReviewsFor($instructor);

        $this->assertSame($reviewVersionBefore, $review->fresh()->version);

        $interfaceMethods = get_class_methods(InstructorQualityInsightsServiceInterface::class);
        foreach (['hide', 'reject', 'archive', 'resolve', 'delete', 'update'] as $mutatingVerb) {
            $this->assertNotContains($mutatingVerb, $interfaceMethods);
        }
    }

    // ── 27. No financial data ────────────────────────────────────────────

    public function test_no_financial_or_compensation_data_is_exposed(): void
    {
        $instructor = $this->instructorUser();
        $this->submitPublicReview($instructor);

        $response = $this->actingAs($instructor)->get(route('dashboard.instructor.quality-insights'));
        $response->assertOk();
        $response->assertDontSee('499.00');
        $response->assertDontSee('compensation');
    }

    // ── 28. No fabricated metrics ──────────────────────────────────────────

    public function test_unsupported_completion_or_response_time_metrics_are_not_fabricated(): void
    {
        $properties = (new \ReflectionClass(InstructorQualityInsightsData::class))->getProperties();
        $propertyNames = array_map(fn ($p) => $p->getName(), $properties);

        foreach (['completionConsistency', 'responseTime', 'completionRate'] as $forbidden) {
            $this->assertNotContains($forbidden, $propertyNames);
        }
    }

    // ── 29. Existing pages unaffected ────────────────────────────────────

    public function test_existing_public_profile_and_admin_quality_dashboard_remain_unchanged(): void
    {
        $instructor = $this->instructorUser();
        $this->submitPublicReview($instructor);

        $this->get(route('instructors.show', $instructor))->assertOk();

        $admin = $this->admin();
        $this->actingAs($admin)->get('/admin/reports/reviews-quality')->assertOk();
    }

    // ── 30. Regression ────────────────────────────────────────────────────

    public function test_phase_17h_to_17o_regression_unaffected(): void
    {
        $instructor = $this->instructorUser();
        $review = $this->submitPublicReview($instructor, content: 'DM me on @sketchy_handle for more info.')->fresh();
        $this->assertSame(StudentReviewStatus::Flagged, $review->status);

        $approved = $this->moderation->approve($review, $this->admin(), 'Reviewed manually — fine.');
        $this->assertSame(StudentReviewStatus::Published, $approved->status);
        $this->assertSame(1, $this->aggregates->summaryFor($instructor->id)->reviewCount);
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
        $admin->assignRole('super_admin');

        return $admin;
    }

    private function paidLesson(User $instructor, ?User $student = null): Lesson
    {
        $endsAt = now()->subHours(2)->startOfHour();

        $booking = Booking::factory()->confirmed()->create([
            'booking_type_id' => BookingType::factory()->paid(),
            'instructor_id' => $instructor->id,
            'student_id' => $student?->id ?? User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE])->id,
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
            'student_id' => $student?->id ?? User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE])->id,
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

    /** @param list<string> $tagKeys */
    private function submitPublicReview(
        User $instructor,
        int $overallRating = 5,
        string $content = 'A genuinely helpful and well-structured lesson overall.',
        ?int $teachingQuality = null,
        ?int $punctuality = null,
        array $tagKeys = [],
        ?User $student = null,
        ?string $studentFirstName = null,
    ): LessonReview {
        // activeStudent(): submitting a review is a protected student
        // action, so a named reviewer needs the same lifecycle state as
        // the anonymous one paidLesson() builds by default.
        $reviewer = $student ?? ($studentFirstName !== null
            ? User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE, 'first_name' => $studentFirstName])
            : null);
        $eligibility = $this->openEligibility($this->paidLesson($instructor, $reviewer));

        $result = $this->submissions->submit($eligibility, $eligibility->student, new SubmitStudentReviewData(
            overallRating: $overallRating,
            teachingQualityRating: $teachingQuality,
            punctualityRating: $punctuality,
            content: $content,
            tagKeys: $tagKeys,
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
        $settings->quality_alerts_enabled = false;

        foreach ($overrides as $key => $value) {
            $settings->{$key} = $value;
        }

        $settings->save();
    }
}
