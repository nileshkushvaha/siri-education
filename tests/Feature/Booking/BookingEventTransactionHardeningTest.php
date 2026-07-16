<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Booking\DTOs\CancelBookingData;
use App\Booking\Enums\BookingActor;
use App\Booking\Events\BookingCancelled;
use App\Booking\Events\BookingCompleted;
use App\Booking\Events\BookingConfirmed;
use App\Booking\Events\BookingPaymentSucceeded;
use App\Booking\Events\BookingRequested;
use App\Booking\Events\BookingRescheduled;
use App\Booking\Events\MeetingCreated;
use App\Booking\Events\MeetingUpdated;
use App\Models\Booking;
use App\Models\BookingMeeting;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Tests\TestCase;

/**
 * Phase 17U.4 — every Booking domain event now implements
 * ShouldDispatchAfterCommit. BookingConfirmed/BookingCancelled/
 * BookingCompleted are provably dispatched from inside a caller's own
 * outer transaction on live paths (BookingPaymentService::markPaid()/
 * finalizeRefundedBooking(), LessonOutcomeService::finalize()/
 * override()) with ShouldQueue listeners downstream — without this
 * interface those listeners could observe a booking row that was
 * never durably committed. The remaining events in the family are
 * hardened identically for consistency, matching the Lesson domain's
 * already-established convention.
 */
class BookingEventTransactionHardeningTest extends TestCase
{
    public function test_booking_requested_dispatches_after_commit(): void
    {
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, new BookingRequested(new Booking));
    }

    public function test_booking_confirmed_dispatches_after_commit(): void
    {
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, new BookingConfirmed(new Booking));
    }

    public function test_booking_cancelled_dispatches_after_commit(): void
    {
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, new BookingCancelled(
            new Booking,
            new CancelBookingData(BookingActor::System, 'test'),
        ));
    }

    public function test_booking_rescheduled_dispatches_after_commit(): void
    {
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, new BookingRescheduled(
            new Booking,
            CarbonImmutable::now(),
            CarbonImmutable::now()->addHour(),
        ));
    }

    public function test_booking_completed_dispatches_after_commit(): void
    {
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, new BookingCompleted(new Booking));
    }

    public function test_booking_payment_succeeded_dispatches_after_commit(): void
    {
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, new BookingPaymentSucceeded(new Booking));
    }

    public function test_meeting_created_dispatches_after_commit(): void
    {
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, new MeetingCreated(new Booking, new BookingMeeting));
    }

    public function test_meeting_updated_dispatches_after_commit(): void
    {
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, new MeetingUpdated(new Booking, new BookingMeeting));
    }
}
