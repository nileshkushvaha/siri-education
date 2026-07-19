<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Enums\BookingActivityAction;
use App\Booking\Enums\BookingActor;
use App\Booking\Services\RescheduleLimitPolicy;
use App\Models\Booking;
use App\Models\BookingActivity;
use App\Settings\BookingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 24D — pure boundary tests for RescheduleLimitPolicy::decide().
 * Prior successful reschedules are seeded directly as
 * BookingActivityAction::Rescheduled rows (the durable source of
 * truth), never inferred from updated_at or a separate counter.
 */
class RescheduleLimitPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function withLimit(int $limit): RescheduleLimitPolicy
    {
        $settings = app(BookingSettings::class);
        $settings->reschedule_limit = $limit;
        $settings->save();

        return app(RescheduleLimitPolicy::class);
    }

    private function seedSuccessfulReschedules(Booking $booking, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            BookingActivity::factory()->create([
                'booking_id' => $booking->id,
                'action' => BookingActivityAction::Rescheduled,
                'actor_type' => BookingActor::Student,
            ]);
        }
    }

    public function test_student_is_allowed_when_no_reschedules_have_happened_yet(): void
    {
        $policy = $this->withLimit(2);
        $booking = Booking::factory()->create();

        $decision = $policy->decide($booking, BookingActor::Student);

        $this->assertTrue($decision->allowed);
        $this->assertSame('allowed', $decision->policyCode);
        $this->assertSame(0, $decision->priorSuccessfulCount);
        $this->assertSame(2, $decision->remaining());
    }

    public function test_student_is_allowed_when_below_the_limit(): void
    {
        $policy = $this->withLimit(2);
        $booking = Booking::factory()->create();
        $this->seedSuccessfulReschedules($booking, 1);

        $decision = $policy->decide($booking, BookingActor::Student);

        $this->assertTrue($decision->allowed);
        $this->assertSame(1, $decision->priorSuccessfulCount);
        $this->assertSame(1, $decision->remaining());
    }

    public function test_student_is_rejected_once_the_successful_count_equals_the_limit(): void
    {
        $policy = $this->withLimit(2);
        $booking = Booking::factory()->create();
        $this->seedSuccessfulReschedules($booking, 2);

        $decision = $policy->decide($booking, BookingActor::Student);

        $this->assertFalse($decision->allowed);
        $this->assertSame('limit_reached', $decision->policyCode);
        $this->assertSame(0, $decision->remaining());
    }

    public function test_limit_zero_blocks_ordinary_student_rescheduling_immediately(): void
    {
        $policy = $this->withLimit(0);
        $booking = Booking::factory()->create();

        $decision = $policy->decide($booking, BookingActor::Student);

        $this->assertFalse($decision->allowed);
        $this->assertSame(0, $decision->limit);
        $this->assertSame(0, $decision->remaining());
    }

    public function test_limit_one_allows_exactly_one_success(): void
    {
        $policy = $this->withLimit(1);
        $booking = Booking::factory()->create();

        $this->assertTrue($policy->decide($booking, BookingActor::Student)->allowed);

        $this->seedSuccessfulReschedules($booking, 1);

        $this->assertFalse($policy->decide($booking, BookingActor::Student)->allowed);
    }

    public function test_a_count_above_a_newly_reduced_limit_is_rejected(): void
    {
        $booking = Booking::factory()->create();
        $this->seedSuccessfulReschedules($booking, 3);

        $policy = $this->withLimit(2);

        $decision = $policy->decide($booking, BookingActor::Student);

        $this->assertFalse($decision->allowed);
        $this->assertSame(3, $decision->priorSuccessfulCount);
        $this->assertSame(0, $decision->remaining());
    }

    public function test_increasing_the_limit_after_exhaustion_permits_another_attempt(): void
    {
        $booking = Booking::factory()->create();
        $this->seedSuccessfulReschedules($booking, 2);

        $exhausted = $this->withLimit(2)->decide($booking, BookingActor::Student);
        $this->assertFalse($exhausted->allowed);

        $raised = $this->withLimit(3)->decide($booking, BookingActor::Student);
        $this->assertTrue($raised->allowed);
        $this->assertSame(1, $raised->remaining());
    }

    public function test_negative_configured_limit_is_clamped_to_zero(): void
    {
        $settings = app(BookingSettings::class);
        $settings->reschedule_limit = 0;
        $settings->save();
        $policy = app(RescheduleLimitPolicy::class);

        $booking = Booking::factory()->create();

        $decision = $policy->decide($booking, BookingActor::Student);

        $this->assertSame(0, $decision->limit);
        $this->assertFalse($decision->allowed);
    }

    public function test_instructor_reschedule_is_not_governed_by_the_limit(): void
    {
        $policy = $this->withLimit(1);
        $booking = Booking::factory()->create();
        $this->seedSuccessfulReschedules($booking, 5);

        $decision = $policy->decide($booking, BookingActor::Instructor);

        $this->assertTrue($decision->allowed);
        $this->assertSame('not_student_governed', $decision->policyCode);
        $this->assertTrue($decision->overrideApplies);
    }

    public function test_admin_reschedule_is_not_governed_by_the_limit(): void
    {
        $policy = $this->withLimit(1);
        $booking = Booking::factory()->create();
        $this->seedSuccessfulReschedules($booking, 5);

        $decision = $policy->decide($booking, BookingActor::Admin);

        $this->assertTrue($decision->allowed);
        $this->assertSame('not_student_governed', $decision->policyCode);
    }

    public function test_system_reschedule_is_not_governed_by_the_limit(): void
    {
        $policy = $this->withLimit(1);
        $booking = Booking::factory()->create();
        $this->seedSuccessfulReschedules($booking, 5);

        $decision = $policy->decide($booking, BookingActor::System);

        $this->assertTrue($decision->allowed);
        $this->assertSame('not_student_governed', $decision->policyCode);
    }

    public function test_only_this_bookings_reschedules_are_counted_not_siblings(): void
    {
        $policy = $this->withLimit(2);
        $booking = Booking::factory()->create();
        $sibling = Booking::factory()->create();

        $this->seedSuccessfulReschedules($sibling, 2);

        $decision = $policy->decide($booking, BookingActor::Student);

        $this->assertTrue($decision->allowed);
        $this->assertSame(0, $decision->priorSuccessfulCount);
    }

    public function test_only_rescheduled_activity_rows_are_counted_not_other_action_types(): void
    {
        $policy = $this->withLimit(2);
        $booking = Booking::factory()->create();

        BookingActivity::factory()->create(['booking_id' => $booking->id, 'action' => BookingActivityAction::Requested]);
        BookingActivity::factory()->create(['booking_id' => $booking->id, 'action' => BookingActivityAction::Confirmed]);

        $decision = $policy->decide($booking, BookingActor::Student);

        $this->assertSame(0, $decision->priorSuccessfulCount);
    }
}
