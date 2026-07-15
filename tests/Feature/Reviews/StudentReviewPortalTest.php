<?php

declare(strict_types=1);

namespace Tests\Feature\Reviews;

use App\Booking\Enums\BookingPaymentStatus;
use App\Enums\InstructorStatus;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\Enums\LessonOutcome;
use App\Livewire\Frontend\Student\ReviewsPortal;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Lesson;
use App\Models\LessonReview;
use App\Models\LessonReviewEligibility;
use App\Models\User;
use App\Reviews\Contracts\StudentReviewServiceInterface;
use App\Reviews\DTOs\SubmitStudentReviewData;
use App\Reviews\Enums\LessonReviewEligibilityMode;
use App\Reviews\Enums\StudentReviewStatus;
use App\Settings\ReviewSettings;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 17R — student review portal: the placeholder page is replaced,
 * eligibility/review data is strictly own-student scoped, submission
 * goes through the existing Phase 17I service, and closed windows
 * never show an active action.
 */
class StudentReviewPortalTest extends TestCase
{
    use RefreshDatabase;

    private LessonLifecycleServiceInterface $lifecycle;

    private LessonOutcomeServiceInterface $outcomes;

    private StudentReviewServiceInterface $submissions;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'instructor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);

        $this->lifecycle = app(LessonLifecycleServiceInterface::class);
        $this->outcomes = app(LessonOutcomeServiceInterface::class);
        $this->submissions = app(StudentReviewServiceInterface::class);

        $this->enableReviews();
    }

    // ── 1. Placeholder replaced ───────────────────────────────────────────

    public function test_student_review_portal_replaces_the_placeholder(): void
    {
        $eligibility = $this->openEligibility($this->paidLesson());

        $response = $this->actingAs($this->activate($eligibility->student))->get(route('dashboard.reviews'));

        $response->assertOk();
        $response->assertSee('Review Opportunities');
        $response->assertDontSee('Complete a course and share your experience');
    }

    // ── 2. Own data only ──────────────────────────────────────────────────

    public function test_student_sees_only_their_own_open_eligibility(): void
    {
        $mine = $this->openEligibility($this->paidLesson(instructorFirstName: 'Ownilda'));
        $other = $this->openEligibility($this->paidLesson(instructorFirstName: 'Foreignia'));
        $this->assertNotSame($mine->student_id, $other->student_id);

        Livewire::actingAs($this->activate($mine->student))
            ->test(ReviewsPortal::class)
            ->assertSee('Ownilda')
            ->assertDontSee('Foreignia');
    }

    // ── 3–4. Submission through the existing service ─────────────────────

    public function test_student_can_submit_from_the_portal(): void
    {
        $eligibility = $this->openEligibility($this->paidLesson());

        Livewire::actingAs($this->activate($eligibility->student))
            ->test(ReviewsPortal::class)
            ->call('startSubmit', $eligibility->id)
            ->set('overall_rating', 5)
            ->set('content', 'Submitted straight from the new portal page.')
            ->call('submitReview')
            ->assertHasNoErrors();

        $review = LessonReview::query()->where('eligibility_id', $eligibility->id)->firstOrFail();
        $this->assertSame('Submitted straight from the new portal page.', $review->content);
        $this->assertSame(LessonReviewEligibilityMode::PublicReview, $review->review_mode);
    }

    public function test_private_demo_eligibility_submits_private_feedback(): void
    {
        $eligibility = $this->openEligibility($this->demoLesson());
        $this->assertSame(LessonReviewEligibilityMode::PrivateFeedback, $eligibility->eligibility_mode);

        Livewire::actingAs($this->activate($eligibility->student))
            ->test(ReviewsPortal::class)
            ->call('startSubmit', $eligibility->id)
            ->set('overall_rating', 4)
            ->set('content', 'Private feedback typed into the portal form.')
            ->call('submitReview')
            ->assertHasNoErrors();

        $review = LessonReview::query()->where('eligibility_id', $eligibility->id)->firstOrFail();
        $this->assertSame(LessonReviewEligibilityMode::PrivateFeedback, $review->review_mode);
        $this->assertSame(StudentReviewStatus::Private, $review->status);
    }

    // ── 5–6. Closed windows show no action ────────────────────────────────

    public function test_expired_eligibility_has_no_submit_action(): void
    {
        $eligibility = $this->openEligibility($this->paidLesson());
        $eligibility->forceFill(['expires_at' => now()->subDay()])->saveQuietly();

        Livewire::actingAs($this->activate($eligibility->student))
            ->test(ReviewsPortal::class)
            ->assertDontSee('Write a review');
    }

    public function test_used_eligibility_has_no_duplicate_action(): void
    {
        $eligibility = $this->openEligibility($this->paidLesson());
        $this->submissions->submit($eligibility, $eligibility->student, new SubmitStudentReviewData(
            overallRating: 5,
            content: 'Already submitted once through the service.',
        ));

        Livewire::actingAs($this->activate($eligibility->student))
            ->test(ReviewsPortal::class)
            ->assertDontSee('Write a review');
    }

    // ── 7–8. Review list visibility ───────────────────────────────────────

    public function test_student_can_view_their_own_submitted_reviews(): void
    {
        $eligibility = $this->openEligibility($this->paidLesson());
        $this->submissions->submit($eligibility, $eligibility->student, new SubmitStudentReviewData(
            overallRating: 5,
            content: 'My own review body, visible to me in the portal.',
        ));

        Livewire::actingAs($this->activate($eligibility->student))
            ->test(ReviewsPortal::class)
            ->assertSee('My own review body, visible to me in the portal.');
    }

    public function test_student_cannot_view_another_students_review_portal_data(): void
    {
        $theirs = $this->openEligibility($this->paidLesson());
        $this->submissions->submit($theirs, $theirs->student, new SubmitStudentReviewData(
            overallRating: 5,
            content: 'A distinctly private review body marker 456123.',
        ));

        $unrelatedStudent = User::factory()->create(['status' => 'active']);
        $unrelatedStudent->assignRole('student');

        Livewire::actingAs($unrelatedStudent)
            ->test(ReviewsPortal::class)
            ->assertDontSee('456123');

        // A direct edit attempt against a foreign review id 404s
        // (ownership-scoped findOrFail — the record is unreachable).
        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($unrelatedStudent)
            ->test(ReviewsPortal::class)
            ->call('startEdit', LessonReview::query()->firstOrFail()->id);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function activate(User $student): User
    {
        $student->forceFill(['status' => 'active'])->save();
        $student->assignRole('student');

        return $student;
    }

    private function paidLesson(?string $instructorFirstName = null): Lesson
    {
        $instructor = User::factory()->create([
            'status' => 'active',
            ...($instructorFirstName ? ['name' => $instructorFirstName, 'first_name' => $instructorFirstName] : []),
        ]);
        $instructor->profile->update(['profile_visibility' => 'public', 'instructor_status' => InstructorStatus::Approved]);
        $instructor->assignRole('instructor');

        $endsAt = now()->subHours(2)->startOfHour();

        $booking = Booking::factory()->confirmed()->create([
            'booking_type_id' => BookingType::factory()->paid(),
            'instructor_id' => $instructor->id,
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
        $instructor = User::factory()->create(['status' => 'active']);
        $instructor->profile->update(['profile_visibility' => 'public', 'instructor_status' => InstructorStatus::Approved]);
        $instructor->assignRole('instructor');

        $endsAt = now()->subHours(2)->startOfHour();

        $booking = Booking::factory()->confirmed()->create([
            'instructor_id' => $instructor->id,
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
        $settings->review_editing_enabled = true;
        $settings->review_edit_window_hours = 24;
        $settings->quality_alerts_enabled = false;

        foreach ($overrides as $key => $value) {
            $settings->{$key} = $value;
        }

        $settings->save();
    }
}
