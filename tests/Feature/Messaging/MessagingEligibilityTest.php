<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Enums\InstructorStatus;
use App\Enums\StudentStatus;
use App\Messaging\Services\MessagingEligibilityService;
use App\Models\Booking;
use App\Models\BookingType;
use App\Settings\MessagingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/**
 * SRS §17.29 "Messaging Eligibility" — every qualifying and
 * disqualifying relationship/lifecycle condition.
 */
class MessagingEligibilityTest extends TestCase
{
    use CreatesMessagingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureMessagingRoles();
    }

    public function test_confirmed_paid_booking_is_eligible(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);

        $this->assertTrue(app(MessagingEligibilityService::class)->isEligible($student, $instructor));
    }

    public function test_pending_unpaid_booking_is_not_eligible(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $type = BookingType::factory()->create(['key' => 'pending_type', 'is_paid' => true]);

        Booking::factory()->create([
            'student_id' => $student->id,
            'instructor_id' => $instructor->id,
            'booking_type_id' => $type->id,
            'status' => BookingStatus::Pending,
            'payment_status' => BookingPaymentStatus::Pending,
        ]);

        $this->assertFalse(app(MessagingEligibilityService::class)->isEligible($student, $instructor));
    }

    public function test_no_relationship_at_all_is_not_eligible(): void
    {
        $this->assertFalse(app(MessagingEligibilityService::class)->isEligible($this->student(), $this->instructor()));
    }

    public function test_active_learning_plan_is_eligible(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->activeLearningPlan($student, $instructor);

        $this->assertTrue(app(MessagingEligibilityService::class)->isEligible($student, $instructor));
    }

    public function test_upcoming_lesson_is_eligible_and_resolves_to_its_booking(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $lesson = $this->upcomingLesson($student, $instructor);

        $context = app(MessagingEligibilityService::class)->findEligibleContext($student, $instructor);

        $this->assertSame(Booking::class, $context[0]);
        $this->assertSame($lesson->booking_id, $context[1]);
    }

    /**
     * SRS §17.28 "Demo-only messaging may be restricted or limited": an
     * upcoming demo lesson alone — with no paid booking and no active
     * learning plan — must never grant messaging eligibility.
     */
    public function test_an_upcoming_demo_lesson_alone_is_not_eligible(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->upcomingDemoLesson($student, $instructor);

        $this->assertFalse(app(MessagingEligibilityService::class)->isEligible($student, $instructor));
    }

    public function test_a_paid_upcoming_lesson_alongside_an_unrelated_demo_lesson_is_still_eligible(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->upcomingDemoLesson($student, $instructor);
        $this->upcomingLesson($student, $instructor);

        $this->assertTrue(app(MessagingEligibilityService::class)->isEligible($student, $instructor));
    }

    public function test_recently_completed_lesson_within_window_is_eligible(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        app(MessagingSettings::class)->post_lesson_window_days = 7;
        $this->recentlyCompletedLesson($student, $instructor, daysAgo: 2);

        $this->assertTrue(app(MessagingEligibilityService::class)->isEligible($student, $instructor));
    }

    public function test_completed_lesson_outside_window_is_not_eligible(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $settings = app(MessagingSettings::class);
        $settings->post_lesson_window_days = 7;
        $settings->save();
        $this->recentlyCompletedLesson($student, $instructor, daysAgo: 30);

        $this->assertFalse(app(MessagingEligibilityService::class)->isEligible($student, $instructor));
    }

    public function test_suspended_student_is_not_eligible_even_with_a_qualifying_booking(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);
        $student->profile()->update(['student_status' => StudentStatus::Suspended]);

        $this->assertFalse(app(MessagingEligibilityService::class)->isEligible($student, $instructor));
    }

    public function test_suspended_instructor_is_not_eligible_even_with_a_qualifying_booking(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $this->confirmedPaidBooking($student, $instructor);
        $instructor->profile()->update(['instructor_status' => InstructorStatus::Suspended]);

        $this->assertFalse(app(MessagingEligibilityService::class)->isEligible($student, $instructor));
    }

    public function test_context_belongs_to_both_participants_check(): void
    {
        $student = $this->student();
        $instructor = $this->instructor();
        $otherInstructor = $this->instructor();
        $booking = $this->confirmedPaidBooking($student, $instructor);

        $eligibility = app(MessagingEligibilityService::class);

        $this->assertTrue($eligibility->contextBelongsToBoth(Booking::class, $booking->id, $student, $instructor));
        $this->assertFalse($eligibility->contextBelongsToBoth(Booking::class, $booking->id, $student, $otherInstructor));
    }
}
