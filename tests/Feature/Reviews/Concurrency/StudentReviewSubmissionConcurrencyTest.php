<?php

declare(strict_types=1);

namespace Tests\Feature\Reviews\Concurrency;

use App\Booking\Enums\BookingPaymentStatus;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\Enums\LessonOutcome;
use App\Models\Booking;
use App\Models\BookingType;
use App\Models\Lesson;
use App\Models\LessonReview;
use App\Models\LessonReviewEligibility;
use App\Reviews\Enums\LessonReviewEligibilityStatus;
use App\Settings\ReviewSettings;

/**
 * Review-eligibility consumption is otherwise only proven via
 * sequential stale-copy simulation (StudentReviewSubmissionTest::
 * test_concurrent_submissions_create_one_review). This is a TRUE
 * cross-process race: two independent worker processes call
 * StudentReviewService::submit() for the same eligibility at the same
 * instant, over separate MySQL connections — proving the eligibility
 * row lock + idempotent-if-already-Used guard in
 * SubmitLessonReviewAction converge to exactly one review under
 * genuine concurrency, not just an in-process simulation.
 */
class StudentReviewSubmissionConcurrencyTest extends ConcurrencyTestCase
{
    public function test_two_concurrent_submissions_converge_to_exactly_one_review(): void
    {
        $this->enableReviews();
        $eligibility = $this->openEligibility($this->paidLesson());

        $results = $this->race([
            ['submit-lesson-review', [
                'eligibility_id' => $eligibility->id,
                'student_id' => $eligibility->student_id,
                'overall_rating' => 5,
                'content' => 'A genuinely helpful and well-structured lesson overall.',
            ]],
            ['submit-lesson-review', [
                'eligibility_id' => $eligibility->id,
                'student_id' => $eligibility->student_id,
                'overall_rating' => 4,
                'content' => 'Also a genuinely helpful lesson, slightly different wording.',
            ]],
        ]);

        foreach ($results as $result) {
            $this->assertTrue($result['ok'] ?? false, 'Concurrent review submitter failed: '.json_encode($result));
        }

        $appliedCount = count(array_filter($results, static fn (array $r): bool => $r['result']['applied'] === true));
        $this->assertSame(
            1,
            $appliedCount,
            'Exactly one of the two concurrent submitters must have applied a new review — the other must observe the already-Used idempotent-repeat path.',
        );

        $this->assertSame(
            1,
            LessonReview::query()->where('eligibility_id', $eligibility->id)->count(),
            'Concurrent submissions against the same eligibility must converge to exactly one review row.',
        );

        $this->assertSame(LessonReviewEligibilityStatus::Used, $eligibility->fresh()->status);
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

        return app(LessonLifecycleServiceInterface::class)->createFromBooking($booking);
    }

    private function openEligibility(Lesson $lesson): LessonReviewEligibility
    {
        app(LessonOutcomeServiceInterface::class)->finalize($lesson->refresh(), LessonOutcome::Completed);

        return LessonReviewEligibility::query()->where('lesson_id', $lesson->id)->firstOrFail();
    }

    private function enableReviews(): void
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
        $settings->save();
    }
}
