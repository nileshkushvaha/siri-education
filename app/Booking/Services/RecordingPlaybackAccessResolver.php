<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\Enums\RecordingPlaybackState;
use App\Booking\Enums\RecordingStatus;
use App\Country\Enums\CountryFeature;
use App\Country\Services\CountryFeatureResolver;
use App\Country\Services\CountryResolver;
use App\Exceptions\Student\StudentActionNotAvailableException;
use App\Lessons\Enums\LessonOutcome;
use App\Models\Booking;
use App\Models\Lesson;
use App\Models\Recording;
use App\Models\User;
use App\Services\Student\StudentLifecycleService;
use App\Settings\MeetingSettings;

/**
 * The single answer to "may THIS student watch THIS recording, and
 * what should they be told about it" (SRS §12.20).
 *
 * Authorization is still RecordingPolicy's — the policy calls
 * isWatchableBy() for its student branch, so there is exactly one
 * place the student rule is written and the Gate, the Livewire
 * booking detail, and the watch page cannot drift apart.
 *
 * The student rule, in order, every gate fail-closed:
 *
 *   1. student playback is switched on
 *      (MeetingSettings::recording_student_playback_enabled), AND the
 *      platform-wide recording CAPABILITY is on for the student's
 *      country (FeatureSettings::recording_enabled via
 *      CountryFeatureResolver). Deliberately NOT the acquisition
 *      switches — MeetingSettings::recording_enabled ("record sessions
 *      by default") and the per-provider google_meet/zoom recording
 *      flags decide whether NEW recordings are made; turning them off
 *      must not make recordings that already exist vanish from the
 *      students they were made for;
 *   2. the viewer IS the recording's student — by authenticated
 *      identity against the canonical row, never by any id the
 *      client supplied;
 *   3. the viewer passes the same strict student lifecycle guard the
 *      meeting join link uses (StudentLifecycleService) — a suspended
 *      or archived student loses playback exactly as they lose the
 *      join link;
 *   4. the teaching session was actually DELIVERED according to the
 *      canonical lesson lifecycle: the booking's Lesson carries a
 *      finalized Completed outcome (Lesson::hasFinalizedOutcome() with
 *      LessonOutcome::Completed — the same fact earnings, reviews and
 *      referrals key off). An upcoming, live, cancelled, no-show or
 *      disputed lesson exposes nothing, whatever the recording row says;
 *   5. the recording is serveable (Available with a stored object);
 *   6. no administrator has withheld it.
 *
 * Nothing here consults the storage backend, and nothing here knows
 * what a provider is: the rule is the same for Google Drive today and
 * S3 later.
 */
final class RecordingPlaybackAccessResolver
{
    public function __construct(
        private readonly MeetingSettings $settings,
        private readonly CountryFeatureResolver $countryFeatures,
        private readonly CountryResolver $countries,
        private readonly StudentLifecycleService $studentLifecycle,
    ) {}

    /**
     * Whether student playback is open at all for this viewer: the
     * playback policy switch, the platform recording capability for
     * their country, and their own student lifecycle standing.
     */
    public function isEnabledFor(User $viewer): bool
    {
        if (! $this->settings->recording_student_playback_enabled) {
            return false;
        }

        if (! $this->countryFeatures->isEnabled(CountryFeature::RecordingAvailability, $this->countries->forStudent($viewer))) {
            return false;
        }

        try {
            $this->studentLifecycle->assertEligibleForStudentAction($viewer);
        } catch (StudentActionNotAvailableException) {
            return false;
        }

        return true;
    }

    /** The student authorization rule. Admin access is NOT decided here. */
    public function isWatchableBy(User $viewer, Recording $recording): bool
    {
        if ((int) $recording->student_id !== (int) $viewer->id) {
            return false;
        }

        if (! $this->isEnabledFor($viewer)) {
            return false;
        }

        if (! $this->lessonWasDelivered($recording->booking?->lesson)) {
            return false;
        }

        if (! $recording->isPlayable()) {
            return false;
        }

        return ! $recording->isStudentAccessWithheld();
    }

    /**
     * The canonical "this session happened" fact. Deliberately the
     * finalized Completed OUTCOME rather than BookingStatus or
     * LessonStatus alone: the outcome is the single authoritative,
     * auditable result every downstream domain (earnings, reviews,
     * referrals) already trusts, written only by
     * FinalizeLessonOutcomeAction.
     */
    public function lessonWasDelivered(?Lesson $lesson): bool
    {
        return $lesson !== null
            && $lesson->outcome === LessonOutcome::Completed
            && $lesson->hasFinalizedOutcome();
    }

    /**
     * What the student sees on the booking. The booking's own
     * `recording` relation is the source, so a caller that eager-loads
     * it pays no extra query; the viewer check is repeated here so a
     * mis-scoped caller can never render another student's state.
     */
    public function stateFor(Booking $booking, User $viewer): RecordingPlaybackState
    {
        if ((int) $booking->student_id !== (int) $viewer->id || ! $this->isEnabledFor($viewer)) {
            return RecordingPlaybackState::Hidden;
        }

        $recording = $booking->recording;

        if ($recording === null) {
            return RecordingPlaybackState::Hidden;
        }

        // A row exists from the moment the meeting is created (Pending),
        // long before the lesson happens. Nothing is shown until the
        // lesson has been DELIVERED per the canonical lifecycle — not
        // merely ended, and never for a cancelled or no-show lesson.
        if (! $this->lessonWasDelivered($booking->lesson)) {
            return RecordingPlaybackState::Hidden;
        }

        return match ($recording->status) {
            RecordingStatus::Pending,
            RecordingStatus::Transferring,
            RecordingStatus::Stored => RecordingPlaybackState::Processing,
            RecordingStatus::Available => $recording->isStudentAccessWithheld() || ! $recording->isPlayable()
                ? RecordingPlaybackState::Unavailable
                : RecordingPlaybackState::Available,
            RecordingStatus::Failed => RecordingPlaybackState::Unavailable,
            RecordingStatus::Expired => RecordingPlaybackState::Expired,
        };
    }
}
