<?php

declare(strict_types=1);

namespace App\Lessons\Actions;

use App\Booking\Enums\BookingStatus;
use App\Lessons\Contracts\LessonAttendanceRepositoryInterface;
use App\Lessons\DTOs\AttendanceEvidenceData;
use App\Lessons\DTOs\AttendanceRecordingResult;
use App\Lessons\Enums\LessonParticipant;
use App\Lessons\Exceptions\LessonAttendanceException;
use App\Lessons\Support\AttendanceMetadataSanitizer;
use App\Models\Lesson;
use App\Models\LessonAttendanceRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Idempotently ingests one piece of attendance evidence. Provider data
 * is evidence only — this action never mutates booking or lesson
 * status. Runs in a transaction under a row lock on the attendance
 * record; duplicate fingerprints (replayed webhooks, repeated
 * commands) return applied=false without writing anything. Aggregates
 * are recomputed from the full event log, so out-of-order arrival
 * always merges to the same result. All timestamps are stored UTC.
 */
final class RecordAttendanceAction
{
    public function __construct(
        private readonly LessonAttendanceRepositoryInterface $attendance,
    ) {}

    /** @throws LessonAttendanceException */
    public function execute(Lesson $lesson, AttendanceEvidenceData $evidence, ?User $recorder = null): AttendanceRecordingResult
    {
        $this->assertEligible($lesson, $evidence);

        return DB::transaction(function () use ($lesson, $evidence, $recorder): AttendanceRecordingResult {
            $record = $this->attendance->lockOrCreateForLesson($lesson);

            // Evidence arriving after the record is sealed (or after the
            // lesson left its open state) is late: stored for audit,
            // flagged for administrative inspection, and never merged into
            // the aggregates — it must not silently rewrite a finalized
            // lesson outcome.
            $late = $record->isFinalized()
                || ! $lesson->status->isOpen()
                || $lesson->booking?->status !== BookingStatus::Confirmed;

            $fingerprint = $evidence->fingerprint();

            if ($this->attendance->eventExists($lesson, $fingerprint)) {
                return new AttendanceRecordingResult($record, applied: false);
            }

            $this->attendance->createEvent([
                'lesson_attendance_record_id' => $record->id,
                'lesson_id' => $lesson->id,
                'participant' => $evidence->participant,
                'source' => $evidence->source,
                'provider_reference' => $evidence->providerReference,
                'provider_event_id' => $evidence->providerEventId,
                'fingerprint' => $fingerprint,
                'joined_at' => $evidence->joinedAtUtc(),
                'left_at' => $evidence->leftAtUtc(),
                'attended_seconds' => $evidence->attendedSeconds,
                'is_late' => $late,
                'metadata' => AttendanceMetadataSanitizer::sanitize($evidence->metadata) ?: null,
                'recorded_by' => $recorder?->id,
            ]);

            if ($late) {
                if ($record->late_evidence_reported_at === null) {
                    $record->fill(['late_evidence_reported_at' => now()->toImmutable()->utc()])->save();
                }

                return new AttendanceRecordingResult($record->refresh(), applied: false, late: true);
            }

            $record->fill([
                ...$this->aggregatesFor($record),
                'source' => $evidence->source,
                'provider_reference' => $evidence->providerReference ?? $record->provider_reference,
                'booking_meeting_id' => $record->booking_meeting_id ?? $lesson->booking?->meeting?->id,
                'technical_issue_reported_at' => $record->technical_issue_reported_at
                    ?? ($evidence->technicalIssueReported ? now()->toImmutable()->utc() : null),
            ])->save();

            return new AttendanceRecordingResult($record->refresh(), applied: true);
        });
    }

    /**
     * Attendance may only be received by an eligible occurrence: a
     * confirmed booking whose lesson is awaiting an outcome takes the
     * normal path; evidence for an already-finalized lesson lands in
     * the late-evidence log. A cancelled (or never-confirmed) booking
     * rejects evidence outright — it can never be made active again.
     *
     * @throws LessonAttendanceException
     */
    private function assertEligible(Lesson $lesson, AttendanceEvidenceData $evidence): void
    {
        $booking = $lesson->booking;

        if ($booking === null || in_array($booking->status, [BookingStatus::Cancelled, BookingStatus::Pending], strict: true)) {
            throw new LessonAttendanceException('Attendance can only be recorded for a confirmed booking.');
        }

        if ($evidence->joinedAt === null && $evidence->attendedSeconds === null && ! $evidence->technicalIssueReported) {
            throw new LessonAttendanceException('Attendance evidence must carry a join time, a duration, or a technical-issue report.');
        }

        if ($evidence->joinedAt !== null && $evidence->leftAt !== null
            && $evidence->leftAtUtc()->isBefore($evidence->joinedAtUtc())) {
            throw new LessonAttendanceException('Attendance evidence cannot leave before it joins.');
        }
    }

    /**
     * Recompute both parties' aggregates from the append-only event log.
     * Timed intervals are merged as a union (overlapping provider
     * sessions never double-count); duration-only evidence adds its
     * seconds on top.
     *
     * @return array<string, mixed>
     */
    private function aggregatesFor(LessonAttendanceRecord $record): array
    {
        // Late evidence is audit-only — never merged into the aggregates.
        $events = $this->attendance->eventsForRecord($record)->reject(fn ($e) => $e->is_late);
        $aggregates = [];

        foreach (LessonParticipant::cases() as $participant) {
            $own = $events->where('participant', $participant);
            $joined = $own->pluck('joined_at')->filter();
            $left = $own->pluck('left_at')->filter();

            $intervals = $own
                ->filter(fn ($e) => $e->joined_at !== null && $e->left_at !== null)
                ->map(fn ($e) => [$e->joined_at->getTimestamp(), $e->left_at->getTimestamp()])
                ->all();

            $durationOnly = $own
                ->filter(fn ($e) => $e->attended_seconds !== null && ($e->joined_at === null || $e->left_at === null))
                ->sum('attended_seconds');

            $aggregates["{$participant->value}_first_joined_at"] = $joined->min();
            $aggregates["{$participant->value}_last_left_at"] = $left->max();
            $aggregates["{$participant->value}_join_count"] = $joined->count();
            $aggregates["{$participant->value}_attended_seconds"] = $this->unionSeconds($intervals) + (int) $durationOnly;
        }

        return $aggregates;
    }

    /** @param list<array{0: int, 1: int}> $intervals */
    private function unionSeconds(array $intervals): int
    {
        if ($intervals === []) {
            return 0;
        }

        usort($intervals, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $total = 0;
        [$start, $end] = $intervals[0];

        foreach (array_slice($intervals, 1) as [$from, $to]) {
            if ($from > $end) {
                $total += $end - $start;
                [$start, $end] = [$from, $to];
            } else {
                $end = max($end, $to);
            }
        }

        return $total + ($end - $start);
    }
}
