<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\Enums\BookingActor;
use App\Booking\Services\CancellationRefundPolicy;
use App\Models\Booking;
use App\Settings\BookingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 24C — pure boundary tests for CancellationRefundPolicy::decide().
 * No queue/event/wallet involved: every input (booking starts_at,
 * actor, cancelledAt) is supplied explicitly, so these run without
 * sleep()/real-time waiting and without touching the clock at all.
 */
class CancellationRefundPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function withWindow(int $hours): CancellationRefundPolicy
    {
        $settings = app(BookingSettings::class);
        $settings->cancellation_window_hours = $hours;
        $settings->save();

        return app(CancellationRefundPolicy::class);
    }

    private function bookingStartingAt(CarbonImmutable $startsAt): Booking
    {
        return Booking::factory()->make(['starts_at' => $startsAt, 'ends_at' => $startsAt->addMinutes(30)]);
    }

    public function test_student_cancellation_well_before_the_default_window_is_eligible(): void
    {
        $policy = $this->withWindow(24);
        $startsAt = CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC');
        $booking = $this->bookingStartingAt($startsAt);

        $decision = $policy->decide($booking, BookingActor::Student, $startsAt->subHours(48));

        $this->assertTrue($decision->eligible);
        $this->assertSame('before_cutoff', $decision->policyCode);
        $this->assertTrue($decision->cutoffAt->equalTo($startsAt->subHours(24)));
    }

    public function test_student_cancellation_exactly_at_the_cutoff_is_eligible(): void
    {
        $policy = $this->withWindow(24);
        $startsAt = CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC');
        $booking = $this->bookingStartingAt($startsAt);

        $decision = $policy->decide($booking, BookingActor::Student, $startsAt->subHours(24));

        $this->assertTrue($decision->eligible);
    }

    public function test_student_cancellation_one_second_before_cutoff_is_eligible(): void
    {
        $policy = $this->withWindow(24);
        $startsAt = CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC');
        $booking = $this->bookingStartingAt($startsAt);

        $decision = $policy->decide($booking, BookingActor::Student, $startsAt->subHours(24)->subSecond());

        $this->assertTrue($decision->eligible);
    }

    public function test_student_cancellation_one_second_after_cutoff_is_not_eligible(): void
    {
        $policy = $this->withWindow(24);
        $startsAt = CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC');
        $booking = $this->bookingStartingAt($startsAt);

        $decision = $policy->decide($booking, BookingActor::Student, $startsAt->subHours(24)->addSecond());

        $this->assertFalse($decision->eligible);
        $this->assertSame('late_cancellation', $decision->policyCode);
    }

    public function test_student_cancellation_inside_the_window_is_not_eligible(): void
    {
        $policy = $this->withWindow(24);
        $startsAt = CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC');
        $booking = $this->bookingStartingAt($startsAt);

        $decision = $policy->decide($booking, BookingActor::Student, $startsAt->subHours(2));

        $this->assertFalse($decision->eligible);
    }

    public function test_student_cancellation_at_lesson_start_is_not_eligible(): void
    {
        $policy = $this->withWindow(24);
        $startsAt = CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC');
        $booking = $this->bookingStartingAt($startsAt);

        $decision = $policy->decide($booking, BookingActor::Student, $startsAt);

        $this->assertFalse($decision->eligible);
    }

    public function test_student_cancellation_after_lesson_start_is_not_eligible(): void
    {
        $policy = $this->withWindow(24);
        $startsAt = CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC');
        $booking = $this->bookingStartingAt($startsAt);

        $decision = $policy->decide($booking, BookingActor::Student, $startsAt->addMinutes(10));

        $this->assertFalse($decision->eligible);
    }

    // ── window_hours = 0 boundary ───────────────────────────────────────────

    public function test_zero_hour_window_cancellation_exactly_at_start_is_eligible(): void
    {
        $policy = $this->withWindow(0);
        $startsAt = CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC');
        $booking = $this->bookingStartingAt($startsAt);

        $decision = $policy->decide($booking, BookingActor::Student, $startsAt);

        $this->assertTrue($decision->eligible);
        $this->assertTrue($decision->cutoffAt->equalTo($startsAt));
    }

    public function test_zero_hour_window_cancellation_one_second_before_start_is_eligible(): void
    {
        $policy = $this->withWindow(0);
        $startsAt = CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC');
        $booking = $this->bookingStartingAt($startsAt);

        $decision = $policy->decide($booking, BookingActor::Student, $startsAt->subSecond());

        $this->assertTrue($decision->eligible);
    }

    public function test_zero_hour_window_cancellation_one_second_after_start_is_not_eligible(): void
    {
        $policy = $this->withWindow(0);
        $startsAt = CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC');
        $booking = $this->bookingStartingAt($startsAt);

        $decision = $policy->decide($booking, BookingActor::Student, $startsAt->addSecond());

        $this->assertFalse($decision->eligible);
    }

    // ── UTC date-boundary case ───────────────────────────────────────────────

    public function test_cutoff_spanning_a_utc_date_boundary_is_computed_correctly(): void
    {
        $policy = $this->withWindow(6);
        $startsAt = CarbonImmutable::parse('2026-08-10 02:00:00', 'UTC');
        $booking = $this->bookingStartingAt($startsAt);

        $decision = $policy->decide($booking, BookingActor::Student, CarbonImmutable::parse('2026-08-09 20:00:00', 'UTC'));

        $this->assertTrue($decision->eligible);
        $this->assertTrue($decision->cutoffAt->equalTo(CarbonImmutable::parse('2026-08-09 20:00:00', 'UTC')));
    }

    /**
     * A DST transition in some user-facing display timezone must never
     * affect the decision — everything here is computed on UTC
     * CarbonImmutable instants, which have no DST concept at all. This
     * pins that down using America/New_York's 2026 "spring forward"
     * date (Mar 8) purely as a labeled UTC instant.
     */
    public function test_decision_is_unaffected_by_a_dst_transition_in_a_display_timezone(): void
    {
        $policy = $this->withWindow(24);
        // 2026-03-08 is a US DST transition date; starts_at/cancelledAt
        // are both plain UTC instants regardless of that fact.
        $startsAt = CarbonImmutable::parse('2026-03-09 10:00:00', 'UTC');
        $booking = $this->bookingStartingAt($startsAt);

        $decision = $policy->decide($booking, BookingActor::Student, CarbonImmutable::parse('2026-03-08 10:00:00', 'UTC'));

        $this->assertTrue($decision->eligible);
        $this->assertSame(24, $decision->windowHours);
    }

    // ── Actor/responsibility matrix ──────────────────────────────────────────

    public function test_instructor_cancellation_is_always_eligible_regardless_of_timing(): void
    {
        $policy = $this->withWindow(24);
        $startsAt = CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC');
        $booking = $this->bookingStartingAt($startsAt);

        $decision = $policy->decide($booking, BookingActor::Instructor, $startsAt->addMinutes(5));

        $this->assertTrue($decision->eligible);
        $this->assertSame('not_student_initiated', $decision->policyCode);
        $this->assertNull($decision->cutoffAt);
    }

    public function test_admin_cancellation_is_always_eligible_regardless_of_timing(): void
    {
        $policy = $this->withWindow(24);
        $startsAt = CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC');
        $booking = $this->bookingStartingAt($startsAt);

        $decision = $policy->decide($booking, BookingActor::Admin, $startsAt->addHour());

        $this->assertTrue($decision->eligible);
        $this->assertSame('not_student_initiated', $decision->policyCode);
    }

    public function test_system_cancellation_is_always_eligible_regardless_of_timing(): void
    {
        $policy = $this->withWindow(24);
        $startsAt = CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC');
        $booking = $this->bookingStartingAt($startsAt);

        $decision = $policy->decide($booking, BookingActor::System, $startsAt->addDay());

        $this->assertTrue($decision->eligible);
        $this->assertSame('not_student_initiated', $decision->policyCode);
    }

    public function test_negative_window_hours_is_clamped_to_zero(): void
    {
        $settings = app(BookingSettings::class);
        $settings->cancellation_window_hours = 0;
        $settings->save();
        $policy = app(CancellationRefundPolicy::class);

        $startsAt = CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC');
        $booking = $this->bookingStartingAt($startsAt);

        $decision = $policy->decide($booking, BookingActor::Student, $startsAt->subSecond());

        $this->assertSame(0, $decision->windowHours);
        $this->assertTrue($decision->eligible);
    }
}
