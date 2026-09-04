<?php

declare(strict_types=1);

namespace App\Booking\Contracts;

use App\Booking\DTOs\ProviderRecordingResult;
use App\Booking\Exceptions\BookingException;
use App\Models\BookingMeeting;

/**
 * An OPTIONAL capability a MeetingProviderInterface may also implement
 * (mirrors MeetingAttendanceProviderInterface's existing pattern
 * exactly). SRS §12.31 "Meeting Recording": "the system shall support
 * meeting recording WHERE THE ACTIVE PROVIDER SUPPORTS IT".
 * GoogleCalendarMeetProvider (Meet REST v2 artifacts) and
 * ZoomMeetingProvider (cloud recordings) implement it; each reports
 * supportsRecording() from its own per-provider switch and credentials.
 * ManualMeetingProvider does not, and never can.
 */
interface MeetingRecordingProviderInterface
{
    /** Never makes a network call — a capability/configuration declaration only. */
    public function supportsRecording(): bool;

    /**
     * Null means "not ready yet" (still processing on the provider
     * side) — a transient, retryable state, not a failure.
     *
     * @throws BookingException on a permanent provider-side failure
     */
    public function fetchRecording(BookingMeeting $meeting): ?ProviderRecordingResult;
}
