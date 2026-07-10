<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\DTOs\MeetingUpdateContext;
use App\Models\Booking;
use App\Models\BookingMeeting;

/**
 * Creates and stores meeting details for confirmed bookings, idempotently
 * and safely. createMeeting()/saveManualMeeting() never throw — failures
 * are recorded on the meeting record (status = failed) and logged, never
 * surfaced as an exception to callers triggered from queued listeners or
 * admin actions.
 */
interface BookingMeetingServiceInterface
{
    /**
     * Automatic trigger (BookingConfirmed listener) and admin
     * "Create/Retry Meeting". $providerKey null uses
     * MeetingSettings::default_provider; an explicit key (e.g.
     * 'google_meet') is an admin override. Idempotent: returns the
     * existing meeting untouched if already `created`. Returns null
     * only when the booking is ineligible and no meeting row exists yet.
     */
    public function createMeeting(Booking $booking, ?string $providerKey = null): ?BookingMeeting;

    /**
     * Admin manual create/update — always uses ManualMeetingProvider
     * regardless of MeetingSettings::default_provider. Still enforces
     * booking eligibility and MeetingSettings::manual_provider_enabled.
     */
    public function saveManualMeeting(Booking $booking, MeetingUpdateContext $context): ?BookingMeeting;

    /** Admin "Mark Meeting Cancelled". Null if no meeting exists. */
    public function cancelMeeting(Booking $booking): ?BookingMeeting;

    /** Whether $booking currently qualifies for meeting creation. */
    public function isEligible(Booking $booking): bool;

    public function findForBooking(Booking $booking): ?BookingMeeting;
}
