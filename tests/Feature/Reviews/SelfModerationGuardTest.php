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
use App\Models\LessonReview;
use App\Models\LessonReviewEligibility;
use App\Models\User;
use App\Reviews\Contracts\ReviewModerationServiceInterface;
use App\Reviews\Contracts\ReviewReportServiceInterface;
use App\Reviews\Contracts\StudentReviewServiceInterface;
use App\Reviews\DTOs\SubmitReviewReportData;
use App\Reviews\DTOs\SubmitStudentReviewData;
use App\Reviews\Enums\ReviewReportReason;
use App\Settings\ReviewSettings;
use Database\Seeders\ReviewPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Gate::before grants super_admin a global permission bypass, and
 * Spatie roles are not mutually exclusive.
 * LessonReviewPolicy::moderate()/ReviewReportPolicy::resolve() both
 * independently exclude the reviewed instructor from moderating their
 * own review, but that policy-layer check alone is bypassable by an
 * account that holds both `instructor` and `super_admin` roles.
 * ReviewModerationService::authorize()/ReviewReportService::
 * authorizeResolve() now enforce the same relationship check
 * independently of the Gate call.
 */
class SelfModerationGuardTest extends TestCase
{
    use RefreshDatabase;

    private LessonLifecycleServiceInterface $lifecycle;

    private LessonOutcomeServiceInterface $outcomes;

    private StudentReviewServiceInterface $submissions;

    private ReviewModerationServiceInterface $moderation;

    private ReviewReportServiceInterface $reports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lifecycle = app(LessonLifecycleServiceInterface::class);
        $this->outcomes = app(LessonOutcomeServiceInterface::class);
        $this->submissions = app(StudentReviewServiceInterface::class);
        $this->moderation = app(ReviewModerationServiceInterface::class);
        $this->reports = app(ReviewReportServiceInterface::class);

        $settings = app(ReviewSettings::class);
        $settings->reviews_enabled = true;
        $settings->paid_lesson_reviews_enabled = true;
        $settings->review_window_days = 14;
        $settings->rating_min = 1;
        $settings->rating_max = 5;
        $settings->written_review_required = false;
        $settings->review_min_length = 10;
        $settings->review_max_length = 2000;
        $settings->moderation_model = 'risk_based';
        $settings->auto_publish_clean_reviews = true;
        $settings->review_reporting_enabled = true;
        $settings->save();

        $this->seed(ReviewPermissionSeeder::class);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
    }

    private function instructorWhoIsAlsoSuperAdmin(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->profile->update(['profile_visibility' => 'public', 'instructor_status' => InstructorStatus::Approved]);
        $user->assignRole(['instructor', 'super_admin']);

        return $user;
    }

    private function publishedReviewFor(User $instructor): LessonReview
    {
        $endsAt = now()->subHours(2)->startOfHour();

        $booking = Booking::factory()->confirmed()->create([
            'booking_type_id' => BookingType::factory()->paid(),
            'instructor_id' => $instructor->id,
            'student_id' => User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE])->id,
            'starts_at' => $endsAt->copy()->subMinutes(60),
            'ends_at' => $endsAt,
            'payment_status' => BookingPaymentStatus::Paid,
            'price' => '499.00',
            'currency' => 'INR',
        ]);

        $lesson = $this->lifecycle->createFromBooking($booking);
        $this->outcomes->finalize($lesson->refresh(), LessonOutcome::Completed);

        $eligibility = LessonReviewEligibility::query()->where('lesson_id', $lesson->id)->firstOrFail();

        $result = $this->submissions->submit($eligibility, $eligibility->student, new SubmitStudentReviewData(
            overallRating: 5,
            content: 'A genuinely helpful and well-structured lesson overall.',
        ));

        return $result->review;
    }

    public function test_instructor_who_is_also_super_admin_cannot_moderate_their_own_review(): void
    {
        $instructor = $this->instructorWhoIsAlsoSuperAdmin();
        $review = $this->publishedReviewFor($instructor)->fresh();

        $this->assertTrue($instructor->isSuperAdmin());
        $this->assertSame($instructor->id, $review->instructor_id);

        $this->expectException(AuthorizationException::class);

        $this->moderation->hide($review, $instructor, 'Trying to silence critical feedback about myself.');
    }

    public function test_instructor_who_is_also_super_admin_cannot_resolve_a_report_about_their_own_review(): void
    {
        $instructor = $this->instructorWhoIsAlsoSuperAdmin();
        $review = $this->publishedReviewFor($instructor)->fresh();

        $reporter = User::factory()->activeStudent()->create(['status' => User::STATUS_ACTIVE]);

        $report = $this->reports->submit($review, $reporter, new SubmitReviewReportData(
            reason: ReviewReportReason::AbusiveLanguage,
        ));

        $this->assertTrue($instructor->isSuperAdmin());

        $this->expectException(AuthorizationException::class);

        $this->reports->dismiss($report, $instructor, 'Dismissing a report about my own review.');
    }

    public function test_an_unrelated_super_admin_can_still_moderate_normally(): void
    {
        // Confirms the fix is scoped to the self-relationship, not a
        // blanket regression on super_admin's moderation ability.
        $instructor = $this->instructorWhoIsAlsoSuperAdmin();
        $review = $this->publishedReviewFor($instructor)->fresh();

        $unrelatedSuperAdmin = User::factory()->create(['status' => 'active']);
        $unrelatedSuperAdmin->assignRole('super_admin');

        $hidden = $this->moderation->hide($review, $unrelatedSuperAdmin, 'Routine check, unrelated admin.');

        $this->assertNotSame($review->instructor_id, $unrelatedSuperAdmin->id);
        $this->assertSame('hidden', $hidden->status->value);
    }
}
