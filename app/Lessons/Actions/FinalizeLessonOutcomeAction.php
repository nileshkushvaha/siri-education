<?php

declare(strict_types=1);

namespace App\Lessons\Actions;

use App\Booking\Enums\BookingStatus;
use App\Enums\ActivityActorType;
use App\Lessons\Contracts\LessonAttendanceRepositoryInterface;
use App\Lessons\Contracts\LessonRepositoryInterface;
use App\Lessons\DTOs\OutcomeFinalizationData;
use App\Lessons\DTOs\OutcomeFinalizationResult;
use App\Lessons\Enums\LessonOutcome;
use App\Lessons\Events\LessonOutcomeFinalized;
use App\Lessons\Exceptions\LessonOutcomeException;
use App\Lessons\Exceptions\TerminalLessonOutcomeException;
use App\Models\Lesson;
use App\Services\AuditTrailService;
use Illuminate\Support\Facades\DB;

/**
 * The single writer of a lesson's finalized outcome. Every caller —
 * the outcome service, the lesson lifecycle (manual completion,
 * no-show finalization, cancellation sync), and the auto-completion
 * sweep — funnels through this action, so the rules, the audit entry,
 * and the exactly-once LessonOutcomeFinalized dispatch hold globally.
 *
 * Runs in a transaction under a row lock on the lesson. Re-finalizing
 * the same outcome is an idempotent no-op; changing a terminal outcome
 * throws — only OverrideLessonOutcomeAction (permission + reason) may
 * correct it. The event is dispatched inside the transaction and
 * released only on commit (ShouldDispatchAfterCommit), so concurrent
 * finalizers can never double-fire it.
 */
final class FinalizeLessonOutcomeAction
{
    public function __construct(
        private readonly LessonRepositoryInterface $lessons,
        private readonly LessonAttendanceRepositoryInterface $attendance,
        private readonly DetermineLessonOutcomeAction $determine,
        private readonly AuditTrailService $audit,
    ) {}

    /** @throws LessonOutcomeException */
    public function execute(Lesson $lesson, OutcomeFinalizationData $data): OutcomeFinalizationResult
    {
        if ($data->outcome === LessonOutcome::Pending) {
            throw new LessonOutcomeException('Pending is not a finalizable outcome.');
        }

        return DB::transaction(function () use ($lesson, $data): OutcomeFinalizationResult {
            $lesson = $this->lessons->lockForUpdate($lesson);
            $previous = $lesson->outcome ?? LessonOutcome::Pending;

            if ($lesson->hasFinalizedOutcome()) {
                if ($previous === $data->outcome) {
                    return new OutcomeFinalizationResult($lesson, applied: false, previousOutcome: $previous);
                }

                if ($previous->isTerminal()) {
                    throw TerminalLessonOutcomeException::between($previous, $data->outcome);
                }
            }

            $this->assertRulesHold($lesson, $data);

            $record = $this->attendance->findForLesson($lesson);

            $lesson->fill([
                'outcome' => $data->outcome,
                'outcome_reason_code' => $data->reasonCode,
                'outcome_notes' => $this->sanitizeNotes($data->notes),
                'outcome_finalized_at' => now()->toImmutable()->utc(),
                'outcome_finalized_by_type' => $data->byType,
                'outcome_finalized_by' => $data->actor?->id,
                'attendance_record_id' => $record?->id,
                'outcome_version' => $lesson->outcome_version + 1,
            ])->save();

            $this->recordAudit($lesson, $previous, $data, $record?->id);

            LessonOutcomeFinalized::dispatch($lesson, $data->outcome, $data->reasonCode);

            return new OutcomeFinalizationResult($lesson, applied: true, previousOutcome: $previous);
        });
    }

    /** @throws LessonOutcomeException */
    private function assertRulesHold(Lesson $lesson, OutcomeFinalizationData $data): void
    {
        $bookingStatus = $lesson->booking?->status;

        // Evidence and outcomes never resurrect a cancelled booking.
        if ($bookingStatus === BookingStatus::Cancelled && $data->outcome !== LessonOutcome::Cancelled) {
            throw new LessonOutcomeException('A cancelled booking can only receive the Cancelled outcome.');
        }

        if ($data->outcome === LessonOutcome::Completed) {
            if ($lesson->ends_at->isFuture() && ! $data->allowEarlyCompletion) {
                throw new LessonOutcomeException('A lesson cannot be completed before its scheduled end time.');
            }

            // A reported technical issue always blocks automatic
            // completion; a human may still decide the lesson happened.
            if ($data->byType === ActivityActorType::System
                && $this->attendance->findForLesson($lesson)?->technical_issue_reported_at !== null) {
                throw new LessonOutcomeException('A reported technical issue prevents automatic completion.');
            }
        }

        if ($data->outcome->isNoShow()) {
            $determination = $this->determine->execute($lesson);

            $violated = match ($data->outcome) {
                LessonOutcome::StudentNoShow => $determination->studentQualifies,
                LessonOutcome::InstructorNoShow => $determination->instructorQualifies,
                LessonOutcome::BothAbsent => $determination->studentQualifies || $determination->instructorQualifies,
                default => false,
            };

            if ($violated) {
                throw new LessonOutcomeException(sprintf(
                    'The outcome "%s" contradicts recorded qualifying attendance.',
                    $data->outcome->value,
                ));
            }
        }
    }

    private function recordAudit(Lesson $lesson, LessonOutcome $previous, OutcomeFinalizationData $data, ?string $attendanceRecordId): void
    {
        $description = sprintf('Lesson %s outcome finalized as %s.', $lesson->id, $data->outcome->label());
        $properties = [
            'previous_outcome' => $previous->value,
            'new_outcome' => $data->outcome->value,
            'reason_code' => $data->reasonCode,
            'outcome_version' => $lesson->outcome_version,
            'booking_id' => $lesson->booking_id,
            'booking_reference' => $lesson->booking?->reference,
            'booking_meeting_id' => $lesson->booking?->meeting?->id,
            'attendance_record_id' => $attendanceRecordId,
        ];

        $data->actor !== null
            ? $this->audit->logUser($data->actor, 'lessons', 'lesson_outcome_finalized', $description, $lesson, $properties)
            : $this->audit->logSystem('lessons', 'lesson_outcome_finalized', $description, $lesson, $properties);
    }

    private function sanitizeNotes(?string $notes): ?string
    {
        if ($notes === null || trim($notes) === '') {
            return null;
        }

        return mb_substr(trim(strip_tags($notes)), 0, 1000);
    }
}
