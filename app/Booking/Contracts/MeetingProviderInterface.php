<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\DTOs\MeetingCancellationResult;
use App\Booking\DTOs\MeetingCreationContext;
use App\Booking\DTOs\MeetingCreationResult;
use App\Booking\DTOs\MeetingUpdateContext;
use App\Booking\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\BookingMeeting;

/**
 * The meeting provider abstraction. Implement per platform (manual,
 * Google Meet, Zoom, …), register in BookingServiceProvider, select via
 * MeetingSettings::default_provider (or an explicit admin choice).
 * BookingMeetingService never knows which provider is active.
 */
interface MeetingProviderInterface
{
    /** Stable identifier used in settings and the booking_meetings.provider column (snake_case). */
    public function key(): string;

    /**
     * Create the meeting on the provider side for an already-eligible,
     * confirmed booking with no existing meeting row.
     *
     * @throws BookingException
     */
    public function createMeeting(Booking $booking, MeetingCreationContext $context): MeetingCreationResult;

    /**
     * Update/retry an existing meeting (admin edits a manual link, or
     * re-syncs a pending/failed Google conference).
     *
     * @throws BookingException
     */
    public function updateMeeting(BookingMeeting $meeting, MeetingUpdateContext $context): MeetingCreationResult;

    /**
     * Cancel the meeting at the provider, if applicable.
     *
     * @throws BookingException
     */
    public function cancelMeeting(BookingMeeting $meeting): MeetingCancellationResult;

    /**
     * Whether this provider is enabled AND its credentials pass format
     * validation. Never makes a network call.
     */
    public function isConfigured(): bool;
}
