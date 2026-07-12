<?php

declare(strict_types=1);

namespace App\Lessons\Services;

use App\Lessons\Actions\FinalizeAttendanceAction;
use App\Lessons\Actions\RecordAttendanceAction;
use App\Lessons\Contracts\LessonAttendanceServiceInterface;
use App\Lessons\DTOs\AttendanceEvidenceData;
use App\Lessons\DTOs\AttendanceRecordingResult;
use App\Lessons\Events\AttendanceFinalized;
use App\Lessons\Events\AttendanceRecorded;
use App\Models\Lesson;
use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Orchestrates attendance evidence: authorization for manual sources,
 * idempotent ingestion/finalization through the actions, audit via
 * AuditTrailService (never activity() directly), and after-commit
 * domain events. Duplicate evidence is silently absorbed — no audit
 * entry, no event.
 */
final class LessonAttendanceService implements LessonAttendanceServiceInterface
{
    private const string LOG_NAME = 'lessons';

    public function __construct(
        private readonly RecordAttendanceAction $recordAction,
        private readonly FinalizeAttendanceAction $finalizeAction,
        private readonly AuditTrailService $audit,
    ) {}

    public function record(Lesson $lesson, AttendanceEvidenceData $evidence, ?User $actor = null): AttendanceRecordingResult
    {
        // Manual evidence is a human assertion — the actor must be the
        // lesson's own instructor or hold MarkAttendance:Lesson. System
        // sources (webhook/sync/fallback) carry no actor by design.
        if ($evidence->source->isManual()) {
            if ($actor === null) {
                throw new AuthorizationException('Manual attendance evidence requires an acting user.');
            }

            if (! $actor->can('markAttendance', $lesson)) {
                throw new AuthorizationException('You may not record attendance for this lesson.');
            }
        }

        $result = $this->recordAction->execute($lesson, $evidence, $actor);

        // Late evidence never rewrites a finalized outcome — it is stored,
        // audited, and the record is flagged for administrative inspection
        // (the automated finalizer holds off flagged lessons).
        if ($result->late) {
            $this->log(
                $actor,
                'lesson_attendance_late_evidence',
                sprintf('Lesson %s: late %s attendance evidence recorded after finalization — flagged for review.', $lesson->id, $evidence->participant->label()),
                $lesson,
                [
                    'participant' => $evidence->participant->value,
                    'source' => $evidence->source->value,
                    'provider_reference' => $evidence->providerReference,
                    'attendance_record_id' => $result->record->id,
                    'booking_id' => $lesson->booking_id,
                    'lesson_outcome' => $lesson->outcome?->value,
                ],
            );

            return $result;
        }

        if ($result->applied) {
            $this->log(
                $actor,
                'lesson_attendance_recorded',
                sprintf('Lesson %s: %s attendance evidence recorded (%s).', $lesson->id, $evidence->participant->label(), $evidence->source->label()),
                $lesson,
                [
                    'participant' => $evidence->participant->value,
                    'source' => $evidence->source->value,
                    'provider_reference' => $evidence->providerReference,
                    'attendance_record_id' => $result->record->id,
                    'booking_id' => $lesson->booking_id,
                    'booking_meeting_id' => $result->record->booking_meeting_id,
                ],
            );

            AttendanceRecorded::dispatch($lesson, $result->record, $evidence->participant, $evidence->source);
        }

        return $result;
    }

    public function finalize(Lesson $lesson, ?User $actor = null): AttendanceRecordingResult
    {
        if ($actor !== null && ! $actor->can('markAttendance', $lesson)) {
            throw new AuthorizationException('You may not finalize attendance for this lesson.');
        }

        $result = $this->finalizeAction->execute($lesson, $actor);

        if ($result->applied) {
            $this->log(
                $actor,
                'lesson_attendance_finalized',
                sprintf('Lesson %s attendance finalized.', $lesson->id),
                $lesson,
                [
                    'attendance_record_id' => $result->record->id,
                    'booking_id' => $lesson->booking_id,
                    'booking_meeting_id' => $result->record->booking_meeting_id,
                    'student_attended_seconds' => $result->record->student_attended_seconds,
                    'instructor_attended_seconds' => $result->record->instructor_attended_seconds,
                ],
            );

            AttendanceFinalized::dispatch($lesson, $result->record);
        }

        return $result;
    }

    /** @param array<string, mixed> $properties */
    private function log(?User $actor, string $event, string $description, Lesson $lesson, array $properties = []): void
    {
        $actor !== null
            ? $this->audit->logUser($actor, self::LOG_NAME, $event, $description, $lesson, $properties)
            : $this->audit->logSystem(self::LOG_NAME, $event, $description, $lesson, $properties);
    }
}
