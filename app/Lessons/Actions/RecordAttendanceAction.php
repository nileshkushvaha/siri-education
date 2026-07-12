<?php

declare(strict_types=1);

namespace App\Lessons\Actions;

use App\Booking\Enums\BookingStatus;
use App\Lessons\Contracts\LessonAttendanceRepositoryInterface;
use App\Lessons\DTOs\AttendanceEvidenceData;
use App\Lessons\DTOs\AttendanceRecordingResult;
use App\Lessons\Enums\LessonParticipant;
use App\Lessons\Exceptions\LessonAttendanceException;
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
    /** Metadata keys that may carry secrets or PII — always excluded. */
    private const string SENSITIVE_KEY_PATTERN = '/token|secret|password|passcode|authorization|api[_-]?key|email|phone|address|url|ip/i';

    private const int MAX_METADATA_ENTRIES = 20;

    public function __construct(
        private readonly LessonAttendanceRepositoryInterface $attendance,
    ) {}

    /** @throws LessonAttendanceException */
    public function execute(Lesson $lesson, AttendanceEvidenceData $evidence, ?User $recorder = null): AttendanceRecordingResult
    {
        $this->assertEligible($lesson, $evidence);

        return DB::transaction(function () use ($lesson, $evidence, $recorder): AttendanceRecordingResult {
            $record = $this->attendance->lockOrCreateForLesson($lesson);

            if ($record->isFinalized()) {
                throw new LessonAttendanceException('The attendance record is finalized — no further evidence is accepted.');
            }

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
                'metadata' => $this->sanitizeMetadata($evidence->metadata) ?: null,
                'recorded_by' => $recorder?->id,
            ]);

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
     * confirmed booking whose lesson is still awaiting an outcome.
     * A cancelled booking can never be made active again by evidence.
     *
     * @throws LessonAttendanceException
     */
    private function assertEligible(Lesson $lesson, AttendanceEvidenceData $evidence): void
    {
        $booking = $lesson->booking;

        if ($booking === null || $booking->status !== BookingStatus::Confirmed) {
            throw new LessonAttendanceException('Attendance can only be recorded for a confirmed booking.');
        }

        if (! $lesson->status->isOpen()) {
            throw new LessonAttendanceException('Attendance can only be recorded while the lesson is scheduled or live.');
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
        $events = $this->attendance->eventsForRecord($record);
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

    /**
     * Keep only scalar values under non-sensitive keys — provider
     * payloads are summarized, never stored raw.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, scalar>
     */
    private function sanitizeMetadata(array $metadata): array
    {
        $clean = [];

        foreach ($metadata as $key => $value) {
            if (! is_string($key) || preg_match(self::SENSITIVE_KEY_PATTERN, $key) === 1) {
                continue;
            }

            if (! is_scalar($value)) {
                continue;
            }

            $clean[$key] = is_string($value) ? mb_substr($value, 0, 500) : $value;

            if (count($clean) >= self::MAX_METADATA_ENTRIES) {
                break;
            }
        }

        return $clean;
    }
}
