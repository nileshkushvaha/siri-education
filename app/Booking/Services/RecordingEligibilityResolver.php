<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Contracts\MeetingProviderInterface;
use App\Booking\Contracts\MeetingRecordingProviderInterface;
use App\Booking\DTOs\RecordingEligibilityResult;
use App\Booking\Enums\BookingStatus;
use App\Country\Services\CountryResolver;
use App\Models\Booking;

/**
 * The COMPLETE eligibility chain for a specific booking, wrapping
 * RecordingAvailabilityResolver (global + country + meeting-level
 * flags) and adding every remaining AND-term: confirmed booking,
 * provider capability, both participants' account standing, both
 * participants' explicit consent. Every branch returns a stable
 * machine-readable reason code so callers/tests can assert exactly
 * which gate failed — never a boolean alone.
 *
 * The governing country is the STUDENT's (not the instructor's):
 * recording availability is a data-residency/consent-law concern,
 * which follows the student the same way MarketplaceCountryResolver's
 * pricing/localization already does — distinct from Homework's
 * equivalent gate, which is an instructor action and correctly
 * resolves against the instructor instead.
 */
final class RecordingEligibilityResolver
{
    public function __construct(
        private readonly RecordingAvailabilityResolver $availability,
        private readonly CountryResolver $countryResolver,
    ) {}

    public function evaluate(Booking $booking, MeetingProviderInterface $provider): RecordingEligibilityResult
    {
        // Confirmed at creation time; Completed when the sweep registers a
        // row that was missed (switches fixed after the lesson ran). Never
        // Pending, Cancelled or NoShow — nothing was, or will be, delivered.
        if (! in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::Completed], true)) {
            return RecordingEligibilityResult::ineligible('booking_not_confirmed');
        }

        $student = $booking->student;
        $instructor = $booking->instructor;

        if ($student === null || $instructor === null) {
            return RecordingEligibilityResult::ineligible('participant_missing');
        }

        $country = $this->countryResolver->forStudent($student);

        if (! $this->availability->isAvailable($country)) {
            return RecordingEligibilityResult::ineligible('recording_not_available');
        }

        if (! $provider instanceof MeetingRecordingProviderInterface || ! $provider->supportsRecording()) {
            return RecordingEligibilityResult::ineligible('provider_capability_missing');
        }

        if (! $student->isActive() || ! $instructor->isActive()) {
            return RecordingEligibilityResult::ineligible('participant_lifecycle_restricted');
        }

        if (! (bool) $student->profile?->consents_to_recording) {
            return RecordingEligibilityResult::ineligible('student_consent_missing');
        }

        if (! (bool) $instructor->profile?->consents_to_recording) {
            return RecordingEligibilityResult::ineligible('instructor_consent_missing');
        }

        return RecordingEligibilityResult::eligible();
    }
}
