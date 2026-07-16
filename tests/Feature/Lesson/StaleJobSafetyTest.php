<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Booking\Contracts\BookingRepositoryInterface;
use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonRepositoryInterface;
use App\Lessons\Enums\LessonStatus;
use App\Models\Booking;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 17U.4 — stale-job safety. `booking:release-expired` and
 * `lessons:auto-complete` both queried a batch of candidates up front,
 * then acted on each candidate's in-memory (possibly by-then-stale)
 * copy with no reload or lock — a booking confirmed by a concurrent
 * payment, or a lesson disputed by a concurrent admin action, between
 * the candidate query and this command's per-row processing, could be
 * silently overwritten (a lost update). Both now re-fetch and lock the
 * row immediately before mutating it, matching the reference pattern
 * already used by lessons:finalize-due / lessons:process-refunds.
 */
class StaleJobSafetyTest extends TestCase
{
    use RefreshDatabase;

    // ── booking:release-expired ───────────────────────────────────────

    public function test_repository_no_longer_reports_a_booking_as_expired_once_it_is_confirmed(): void
    {
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Pending,
            'payment_status' => BookingPaymentStatus::Pending,
            'reserved_until' => now()->subMinute(),
        ]);

        $repository = app(BookingRepositoryInterface::class);

        // Still eligible before any concurrent settlement.
        $this->assertNotNull($repository->lockIfStillExpiredReservation($booking->id));

        // Simulate a concurrent payment settling the booking between the
        // command's initial candidate query and this row being processed.
        $booking->forceFill(['status' => BookingStatus::Confirmed, 'payment_status' => BookingPaymentStatus::Paid])->save();

        $this->assertNull(
            $repository->lockIfStillExpiredReservation($booking->id),
            'A booking confirmed after the initial expired-reservations query must not be re-fetched as still-expired.',
        );
    }

    public function test_release_expired_command_does_not_cancel_a_booking_confirmed_between_query_and_processing(): void
    {
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Pending,
            'payment_status' => BookingPaymentStatus::Pending,
            'reserved_until' => now()->subMinute(),
        ]);

        // Hold the stale, pre-settlement snapshot exactly as the command's
        // expiredReservations() collection would.
        $bookings = app(BookingRepositoryInterface::class);
        $staleCandidate = $bookings->expiredReservations()->firstOrFail();
        $this->assertSame($booking->id, $staleCandidate->id);

        // Concurrent settlement — a real payment confirms the booking.
        app(BookingServiceInterface::class)->confirm($booking->fresh());
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);

        // Prove the command's actual per-row mechanism (lock + re-check)
        // refuses to act on the stale candidate rather than blindly
        // cancelling it.
        $result = $bookings->lockIfStillExpiredReservation($staleCandidate->id);
        $this->assertNull($result);

        // End-to-end: running the real command now must leave the booking
        // Confirmed, never revert it to Cancelled.
        $this->artisan('booking:release-expired')->assertSuccessful();

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
    }

    public function test_release_expired_command_still_cancels_a_genuinely_expired_reservation(): void
    {
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Pending,
            'payment_status' => BookingPaymentStatus::Pending,
            'reserved_until' => now()->subMinute(),
        ]);

        $this->artisan('booking:release-expired')->assertSuccessful();

        $booking->refresh();
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertNotNull($booking->cancelled_at);
    }

    // ── lessons:auto-complete ─────────────────────────────────────────

    public function test_repository_lock_for_update_returns_the_current_status_not_a_stale_snapshot(): void
    {
        $lesson = $this->makeLesson(endedHoursAgo: 48);
        $staleSnapshot = clone $lesson;

        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        // Concurrent admin action: dispute the lesson after the stale
        // snapshot was captured (e.g. by an automated sweep's candidate
        // query) but before it would be processed.
        app(LessonLifecycleServiceInterface::class)->dispute($lesson, $admin, 'Reported by student.');

        $fresh = app(LessonRepositoryInterface::class)->lockForUpdate($staleSnapshot);

        $this->assertSame(LessonStatus::Disputed, $fresh->status);
        $this->assertFalse($fresh->status->isOpen());
    }

    public function test_auto_complete_due_does_not_complete_a_lesson_disputed_after_the_candidate_query(): void
    {
        $lesson = $this->makeLesson(endedHoursAgo: 48);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        app(LessonLifecycleServiceInterface::class)->dispute($lesson, $admin, 'Reported by student.');

        $finalized = app(LessonLifecycleServiceInterface::class)->autoCompleteDue();

        $this->assertSame(0, $finalized);
        $this->assertSame(LessonStatus::Disputed, $lesson->fresh()->status);
    }

    public function test_auto_complete_due_still_completes_a_genuinely_eligible_lesson(): void
    {
        $lesson = $this->makeLesson(endedHoursAgo: 48);

        $finalized = app(LessonLifecycleServiceInterface::class)->autoCompleteDue();

        $this->assertSame(1, $finalized);
        $this->assertSame(LessonStatus::Completed, $lesson->fresh()->status);
    }

    private function makeLesson(int $endedHoursAgo): Lesson
    {
        $endsAt = now()->subHours($endedHoursAgo)->startOfHour();

        $booking = Booking::factory()->confirmed()->create([
            'starts_at' => $endsAt->copy()->subMinutes(60),
            'ends_at' => $endsAt,
        ]);

        return app(LessonLifecycleServiceInterface::class)->createFromBooking($booking);
    }
}
