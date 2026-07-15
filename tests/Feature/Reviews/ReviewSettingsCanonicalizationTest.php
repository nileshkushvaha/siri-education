<?php

declare(strict_types=1);

namespace Tests\Feature\Reviews;

use App\Booking\Enums\BookingPaymentStatus;
use App\Enums\InstructorStatus;
use App\Filament\Pages\Settings\PlatformFoundationSettingsPage;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\Enums\LessonOutcome;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Lesson;
use App\Models\LessonReview;
use App\Models\LessonReviewEligibility;
use App\Models\User;
use App\Notifications\Reviews\ReviewRequestedNotification;
use App\Quality\Support\QualityDashboardAccess;
use App\Reviews\Contracts\PublicInstructorReviewServiceInterface;
use App\Reviews\Contracts\ReviewModerationServiceInterface;
use App\Reviews\Contracts\ReviewReportServiceInterface;
use App\Reviews\Contracts\StudentReviewEditingServiceInterface;
use App\Reviews\Contracts\StudentReviewServiceInterface;
use App\Reviews\DTOs\EditStudentReviewData;
use App\Reviews\DTOs\SubmitReviewReportData;
use App\Reviews\DTOs\SubmitStudentReviewData;
use App\Reviews\Enums\ReviewReportReason;
use App\Reviews\Exceptions\ReviewEligibilityException;
use App\Reviews\Exceptions\ReviewNotReportableException;
use App\Settings\FeatureSettings;
use App\Settings\ReviewSettings;
use Database\Seeders\ReviewPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 17U.2 §§2-3 — reviews.reviews_enabled is the ONE canonical
 * switch. features.reviews_enabled (Finding S-1's decoy) is retired.
 * Disabling the canonical switch blocks every new write (eligibility,
 * submission, editing, reporting, the "ready to review" notification)
 * while every historical review, moderation record, and aggregate
 * stays fully intact and visible.
 */
class ReviewSettingsCanonicalizationTest extends TestCase
{
    use RefreshDatabase;

    private LessonLifecycleServiceInterface $lifecycle;

    private LessonOutcomeServiceInterface $outcomes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lifecycle = app(LessonLifecycleServiceInterface::class);
        $this->outcomes = app(LessonOutcomeServiceInterface::class);
    }

    // ── Decoy retirement ─────────────────────────────────────────────

    public function test_features_reviews_enabled_property_no_longer_exists(): void
    {
        $this->assertFalse(property_exists(FeatureSettings::class, 'reviews_enabled'));
    }

    public function test_platform_foundation_settings_page_no_longer_exposes_a_reviews_toggle(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('super_admin');

        $this->actingAs($admin);

        Livewire::test(PlatformFoundationSettingsPage::class)
            ->assertFormFieldDoesNotExist('reviews_enabled');
    }

    public function test_canonical_reviews_enabled_setting_still_functions_after_decoy_removal(): void
    {
        $this->enableReviews(['reviews_enabled' => false]);

        $this->assertFalse(app(ReviewSettings::class)->reviews_enabled);

        $this->enableReviews(['reviews_enabled' => true]);

        $this->assertTrue(app(ReviewSettings::class)->reviews_enabled);
    }

    // ── Disabled-state blocking: new writes ──────────────────────────

    public function test_disabled_reviews_blocks_new_eligibility_from_being_opened(): void
    {
        $this->enableReviews(['reviews_enabled' => false]);

        $lesson = $this->paidLesson();
        $this->outcomes->finalize($lesson->refresh(), LessonOutcome::Completed);

        $this->assertSame(0, LessonReviewEligibility::query()->where('lesson_id', $lesson->id)->count());
    }

    public function test_disabling_reviews_after_an_eligibility_window_already_opened_blocks_a_genuinely_new_submission(): void
    {
        $this->enableReviews();
        $lesson = $this->paidLesson();
        $this->outcomes->finalize($lesson->refresh(), LessonOutcome::Completed);
        $eligibility = LessonReviewEligibility::query()->where('lesson_id', $lesson->id)->firstOrFail();

        // The window was opened while reviews were enabled — disabling
        // the switch afterward must still stop a brand-new submission
        // against that already-open window.
        $this->enableReviews(['reviews_enabled' => false]);

        $this->expectException(ReviewEligibilityException::class);

        app(StudentReviewServiceInterface::class)->submit(
            $eligibility,
            $eligibility->student,
            new SubmitStudentReviewData(overallRating: 5, content: 'A genuinely helpful and well-structured lesson.'),
        );
    }

    public function test_disabling_reviews_does_not_block_an_idempotent_retry_of_an_already_used_eligibility(): void
    {
        $this->enableReviews();
        $lesson = $this->paidLesson();
        $this->outcomes->finalize($lesson->refresh(), LessonOutcome::Completed);
        $eligibility = LessonReviewEligibility::query()->where('lesson_id', $lesson->id)->firstOrFail();

        $first = app(StudentReviewServiceInterface::class)->submit(
            $eligibility,
            $eligibility->student,
            new SubmitStudentReviewData(overallRating: 5, content: 'A genuinely helpful and well-structured lesson.'),
        );

        $this->enableReviews(['reviews_enabled' => false]);

        $second = app(StudentReviewServiceInterface::class)->submit(
            $eligibility->refresh(),
            $eligibility->student,
            new SubmitStudentReviewData(overallRating: 1, content: 'A different retried payload entirely.'),
        );

        $this->assertTrue($first->applied);
        $this->assertFalse($second->applied);
        $this->assertSame($first->review->id, $second->review->id);
    }

    public function test_disabled_reviews_blocks_editing_an_existing_review(): void
    {
        $review = $this->publishedReview();

        $this->enableReviews(['reviews_enabled' => false]);

        $this->expectException(ReviewEligibilityException::class);

        app(StudentReviewEditingServiceInterface::class)->edit(
            $review,
            $review->student,
            new EditStudentReviewData(overallRating: 3, content: 'An edited version of the original feedback text.'),
        );
    }

    public function test_disabled_reviews_blocks_a_new_report(): void
    {
        $review = $this->publishedReview();
        $this->seed(ReviewPermissionSeeder::class);
        $reporter = User::factory()->create(['status' => 'active']);
        $reporter->assignRole('student');

        $this->enableReviews(['reviews_enabled' => false, 'review_reporting_enabled' => true]);

        $this->expectException(ReviewNotReportableException::class);

        app(ReviewReportServiceInterface::class)->submit(
            $review,
            $reporter,
            new SubmitReviewReportData(reason: ReviewReportReason::FakeOrMisleading, explanation: 'Not genuine.'),
        );
    }

    public function test_disabled_reviews_suppresses_the_ready_to_review_notification(): void
    {
        NotificationFacade::fake();

        $this->enableReviews(['reviews_enabled' => false]);

        $lesson = $this->paidLesson();
        $this->outcomes->finalize($lesson->refresh(), LessonOutcome::Completed);

        NotificationFacade::assertNotSentTo($lesson->student, ReviewRequestedNotification::class);
    }

    // ── Disabled-state preservation: historical data untouched ───────

    public function test_disabled_reviews_preserves_visibility_of_an_already_published_review(): void
    {
        $review = $this->publishedReview();
        $instructor = $review->instructor;

        $this->enableReviews(['reviews_enabled' => false]);

        $summary = app(PublicInstructorReviewServiceInterface::class)->summaryFor($instructor);
        $page = app(PublicInstructorReviewServiceInterface::class)->paginatedReviewsFor($instructor);

        $this->assertGreaterThanOrEqual(1, $summary->reviewCount);
        $this->assertSame(1, $page->total());
        $this->assertSame($review->content, $page->items()[0]->content);
    }

    public function test_disabled_reviews_preserves_moderation_queue_access(): void
    {
        $review = $this->publishedReview();

        $this->enableReviews(['reviews_enabled' => false]);

        $this->seed(ReviewPermissionSeeder::class);
        $manager = User::factory()->create(['status' => 'active']);
        $manager->assignRole('manager');
        $this->actingAs($manager);

        $this->assertTrue(QualityDashboardAccess::userCan('ViewReviewModerationQueue'));
        $this->assertTrue($manager->can('moderate', $review));
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function paidLesson(?User $instructor = null): Lesson
    {
        $endsAt = now()->subHours(2)->startOfHour();

        $booking = Booking::factory()->confirmed()->create(array_filter([
            'booking_type_id' => BookingType::factory()->paid(),
            'host_id' => $instructor?->id,
            'starts_at' => $endsAt->copy()->subMinutes(60),
            'ends_at' => $endsAt,
            'payment_status' => BookingPaymentStatus::Paid,
            'price' => '499.00',
            'currency' => 'INR',
        ]));

        return $this->lifecycle->createFromBooking($booking);
    }

    private function publiclyVisibleInstructor(): User
    {
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);

        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->profile->update([
            'profile_visibility' => 'public',
            'instructor_status' => InstructorStatus::Approved,
        ]);
        $instructor->assignRole('instructor');

        return $instructor;
    }

    private function publishedReview(): LessonReview
    {
        $this->enableReviews();
        $lesson = $this->paidLesson($this->publiclyVisibleInstructor());
        $this->outcomes->finalize($lesson->refresh(), LessonOutcome::Completed);
        $eligibility = LessonReviewEligibility::query()->where('lesson_id', $lesson->id)->firstOrFail();

        $result = app(StudentReviewServiceInterface::class)->submit(
            $eligibility,
            $eligibility->student,
            new SubmitStudentReviewData(overallRating: 5, content: 'A genuinely helpful and well-structured lesson.'),
        );

        $this->seed(ReviewPermissionSeeder::class);
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('manager');

        return app(ReviewModerationServiceInterface::class)->approve($result->review, $admin);
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
        $settings->review_editing_enabled = true;
        $settings->review_edit_window_hours = 24;
        $settings->review_reporting_enabled = true;

        foreach ($overrides as $key => $value) {
            $settings->{$key} = $value;
        }

        $settings->save();
    }
}
