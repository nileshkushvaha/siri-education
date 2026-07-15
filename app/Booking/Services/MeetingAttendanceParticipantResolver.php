<?php

declare(strict_types=1);

namespace App\Booking\Services;

use App\Booking\DTOs\ProviderAttendanceEvent;
use App\Lessons\Enums\LessonParticipant;
use App\Models\Booking;
use App\Models\BookingMeeting;

/**
 * Maps a provider participant to the booking's student or instructor —
 * exclusively from stored data, never from what the webhook claims:
 *
 *  1. The meeting's stored participant map
 *     (metadata.attendance_participants.{student,instructor} — provider
 *     refs captured at meeting creation), compared hash-to-hash.
 *  2. The platform convention reference "user:{id}" for the booking's
 *     attendee/host.
 *
 * A provider-supplied role hint may only corroborate: when present and
 * contradicting the stored mapping, resolution fails (spoof attempt →
 * operational review). Unknown or ambiguous participants resolve to
 * null and must never create attendance.
 */
final class MeetingAttendanceParticipantResolver
{
    public function resolve(BookingMeeting $meeting, Booking $booking, ProviderAttendanceEvent $event): ?LessonParticipant
    {
        $matches = $this->matchesFromStoredMap($meeting, $event);

        if ($matches === []) {
            $matches = $this->matchesFromConvention($booking, $event);
        }

        if (count($matches) !== 1) {
            return null; // unknown or ambiguous — operational review, never a guess
        }

        $resolved = $matches[0];

        if ($event->roleHint !== null && $event->roleHint !== $resolved->value) {
            return null; // role claim contradicts stored data — possible spoof
        }

        return $resolved;
    }

    /** @return list<LessonParticipant> */
    private function matchesFromStoredMap(BookingMeeting $meeting, ProviderAttendanceEvent $event): array
    {
        $map = $meeting->metadata['attendance_participants'] ?? null;

        if (! is_array($map)) {
            return [];
        }

        $matches = [];

        foreach (LessonParticipant::cases() as $participant) {
            $stored = $map[$participant->value] ?? null;

            if (is_string($stored) && $stored !== ''
                && hash_equals(ProviderAttendanceEvent::keyFor($stored), $event->participantKey)) {
                $matches[] = $participant;
            }
        }

        return $matches;
    }

    /** @return list<LessonParticipant> */
    private function matchesFromConvention(Booking $booking, ProviderAttendanceEvent $event): array
    {
        $matches = [];

        if ($booking->student_id !== null
            && hash_equals(ProviderAttendanceEvent::keyFor('user:'.$booking->student_id), $event->participantKey)) {
            $matches[] = LessonParticipant::Student;
        }

        if ($booking->instructor_id !== null
            && hash_equals(ProviderAttendanceEvent::keyFor('user:'.$booking->instructor_id), $event->participantKey)) {
            $matches[] = LessonParticipant::Instructor;
        }

        return $matches;
    }
}
