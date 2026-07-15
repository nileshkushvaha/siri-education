<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Booking\Enums\BookingPaymentStatus;
use App\Filament\Widgets\Quality\ModerationQueueWidget;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\Enums\LessonOutcome;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Lesson;
use App\Models\LessonReview;
use App\Models\LessonReviewEligibility;
use App\Models\User;
use App\Reviews\Contracts\ReviewModerationServiceInterface;
use App\Reviews\Contracts\StudentReviewServiceInterface;
use App\Reviews\DTOs\SubmitStudentReviewData;
use App\Reviews\Enums\StudentReviewStatus;
use App\Reviews\Exceptions\InvalidReviewTransitionException;
use App\Settings\ReviewSettings;
use Database\Seeders\ReviewPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 17U.2 §8 — the previously-missing moderation mutation actions
 * on ModerationQueueWidget (approve/reject/hide/restore/archive),
 * every one delegating exclusively to ReviewModerationService, plus
 * §10 authorization hardening: instructors cannot moderate their own
 * profile's reviews even if hypothetically permissioned, and a
 * super-admin bypass never defeats the underlying state machine.
 */
class ReviewModerationWidgetActionsTest extends TestCase
{
    use RefreshDatabase;

    private LessonLifecycleServiceInterface $lifecycle;

    private LessonOutcomeServiceInterface $outcomes;

    private StudentReviewServiceInterface $submissions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lifecycle = app(LessonLifecycleServiceInterface::class);
        $this->outcomes = app(LessonOutcomeServiceInterface::class);
        $this->submissions = app(StudentReviewServiceInterface::class);

        $this->enableReviews();
    }

    public function test_approve_action_publishes_a_submitted_public_review(): void
    {
        $review = $this->submitPublicReview();
        $this->actingAs($this->admin());

        Livewire::test(ModerationQueueWidget::class)
            ->callTableAction('approve', $review, data: ['reason' => null])
            ->assertHasNoTableActionErrors();

        $this->assertSame(StudentReviewStatus::Published, $review->fresh()->status);
    }

    public function test_reject_action_requires_a_reason_and_rejects_the_review(): void
    {
        $review = $this->submitPublicReview();
        $this->actingAs($this->admin());

        Livewire::test(ModerationQueueWidget::class)
            ->callTableAction('reject', $review, data: ['reason' => 'Violates platform content guidelines.'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(StudentReviewStatus::Rejected, $review->fresh()->status);
    }

    public function test_reject_action_form_requires_a_non_empty_reason(): void
    {
        $review = $this->submitPublicReview();
        $this->actingAs($this->admin());

        Livewire::test(ModerationQueueWidget::class)
            ->callTableAction('reject', $review, data: ['reason' => ''])
            ->assertHasTableActionErrors(['reason']);

        $this->assertSame(StudentReviewStatus::Submitted, $review->fresh()->status);
    }

    public function test_hide_action_hides_a_published_review(): void
    {
        $review = $this->publishedReview();
        $this->actingAs($this->admin());

        Livewire::test(ModerationQueueWidget::class)
            ->callTableAction('hide', $review, data: ['reason' => 'Reported and confirmed inappropriate.'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(StudentReviewStatus::Hidden, $review->fresh()->status);
    }

    public function test_restore_action_restores_a_hidden_review_to_published(): void
    {
        $review = $this->publishedReview();
        $admin = $this->admin();
        app(ReviewModerationServiceInterface::class)->hide($review, $admin, 'Temporary hide.');

        $this->actingAs($admin);

        Livewire::test(ModerationQueueWidget::class)
            ->callTableAction('restore', $review, data: ['reason' => 'Determined the report was unfounded.'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(StudentReviewStatus::Published, $review->fresh()->status);
    }

    public function test_archive_action_archives_a_hidden_review(): void
    {
        $review = $this->publishedReview();
        $admin = $this->admin();
        app(ReviewModerationServiceInterface::class)->hide($review, $admin, 'Hidden first.');

        $this->actingAs($admin);

        Livewire::test(ModerationQueueWidget::class)
            ->callTableAction('archive', $review, data: ['reason' => 'Permanently closing this case.'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(StudentReviewStatus::Archived, $review->fresh()->status);
    }

    public function test_a_student_has_no_moderate_or_hide_permission_at_all(): void
    {
        $review = $this->submitPublicReview();
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
        $student = User::factory()->create(['status' => 'active']);
        $student->assignRole('student');

        $this->assertFalse($student->can('moderate', $review));
        $this->assertFalse($student->can('hide', $review));
    }

    public function test_an_instructor_cannot_moderate_their_own_review_even_if_hypothetically_permissioned(): void
    {
        $this->seed(ReviewPermissionSeeder::class);
        $review = $this->submitPublicReview();
        $ownInstructor = $review->instructor;
        // Defense-in-depth scenario: grant the reviewed instructor the
        // staff moderation permission directly (a hypothetical future
        // role change) — the policy must still deny them.
        $ownInstructor->givePermissionTo('Moderate:LessonReview');

        $this->assertFalse($ownInstructor->can('moderate', $review));
    }

    public function test_super_admin_bypass_does_not_defeat_the_underlying_state_machine(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $superAdmin = User::factory()->create(['status' => 'active']);
        $superAdmin->assignRole('super_admin');

        $review = $this->submitPublicReview(); // status: Submitted — restore() only ever targets Published from a valid prior state

        $this->expectException(InvalidReviewTransitionException::class);

        // restore() targets Published; Submitted -> Published IS a valid
        // transition per StudentReviewStatus::allowedTransitions(), so
        // use archive() instead, which Submitted cannot legally reach.
        app(ReviewModerationServiceInterface::class)->archive($review, $superAdmin, 'Attempting an illegal transition.');
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

    private function submitPublicReview(): LessonReview
    {
        $lesson = $this->paidLesson();
        $this->outcomes->finalize($lesson->refresh(), LessonOutcome::Completed);
        $eligibility = LessonReviewEligibility::query()->where('lesson_id', $lesson->id)->firstOrFail();

        $result = $this->submissions->submit($eligibility, $eligibility->student, new SubmitStudentReviewData(
            overallRating: 5,
            content: 'A genuinely helpful and well-structured lesson overall.',
        ));

        return $result->review;
    }

    private function publishedReview(): LessonReview
    {
        $review = $this->submitPublicReview();
        $admin = $this->admin();

        return app(ReviewModerationServiceInterface::class)->approve($review, $admin);
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
        // pre_moderation — a fresh submission stays Submitted (not
        // auto-published) so the approve/reject widget actions have a
        // genuinely pending review to act on.
        $settings->moderation_model = 'pre_moderation';
        $settings->auto_publish_clean_reviews = true;

        foreach ($overrides as $key => $value) {
            $settings->{$key} = $value;
        }

        $settings->save();
    }
}
