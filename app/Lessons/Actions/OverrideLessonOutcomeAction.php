<?php

declare(strict_types=1);

namespace App\Lessons\Actions;

use App\Booking\Enums\BookingStatus;
use App\Enums\ActivityActorType;
use App\Lessons\Contracts\LessonAttendanceRepositoryInterface;
use App\Lessons\Contracts\LessonRepositoryInterface;
use App\Lessons\DTOs\OutcomeFinalizationResult;
use App\Lessons\Enums\LessonOutcome;
use App\Lessons\Events\LessonOutcomeOverridden;
use App\Lessons\Exceptions\LessonOutcomeException;
use App\Models\Lesson;
use App\Models\User;
use App\Services\AuditTrailService;
use Illuminate\Support\Facades\DB;

/**
 * The only sanctioned way to change a terminal lesson outcome — an
 * explicit administrator correction with a mandatory reason, recorded
 * through AuditTrailService::logOverride (previous value, new value,
 * actor, reason, timestamp, booking/meeting references). Authorization
 * (OverrideOutcome:Lesson) is asserted by LessonOutcomeService before
 * this action runs. Absolute rules still hold: a lesson is never
 * marked Completed before its scheduled end, and a cancelled booking
 * only carries the Cancelled outcome.
 */
final class OverrideLessonOutcomeAction
{
    public function __construct(
        private readonly LessonRepositoryInterface $lessons,
        private readonly LessonAttendanceRepositoryInterface $attendance,
        private readonly AuditTrailService $audit,
    ) {}

    /** @throws LessonOutcomeException */
    public function execute(Lesson $lesson, User $admin, LessonOutcome $outcome, string $reason, ?string $notes = null): OutcomeFinalizationResult
    {
        if ($outcome === LessonOutcome::Pending) {
            throw new LessonOutcomeException('An outcome cannot be overridden back to Pending.');
        }

        if (trim($reason) === '') {
            throw new LessonOutcomeException('An outcome override requires a reason.');
        }

        return DB::transaction(function () use ($lesson, $admin, $outcome, $reason, $notes): OutcomeFinalizationResult {
            $lesson = $this->lessons->lockForUpdate($lesson);
            $previous = $lesson->outcome ?? LessonOutcome::Pending;

            if ($previous === $outcome) {
                return new OutcomeFinalizationResult($lesson, applied: false, previousOutcome: $previous);
            }

            if ($lesson->booking?->status === BookingStatus::Cancelled && $outcome !== LessonOutcome::Cancelled) {
                throw new LessonOutcomeException('A cancelled booking can only carry the Cancelled outcome.');
            }

            if ($outcome === LessonOutcome::Completed && $lesson->ends_at->isFuture()) {
                throw new LessonOutcomeException('A lesson cannot be completed before its scheduled end time.');
            }

            $lesson->fill([
                'outcome' => $outcome,
                'outcome_reason_code' => 'admin_override',
                'outcome_notes' => $this->sanitizeNotes($notes),
                'outcome_finalized_at' => now()->toImmutable()->utc(),
                'outcome_finalized_by_type' => ActivityActorType::User,
                'outcome_finalized_by' => $admin->id,
                'attendance_record_id' => $lesson->attendance_record_id
                    ?? $this->attendance->findForLesson($lesson)?->id,
                'outcome_version' => $lesson->outcome_version + 1,
            ])->save();

            $this->audit->logOverride(
                $admin,
                'lessons',
                'lesson_outcome_overridden',
                sprintf('Lesson %s outcome overridden from %s to %s.', $lesson->id, $previous->label(), $outcome->label()),
                $reason,
                $lesson,
                [
                    'previous_outcome' => $previous->value,
                    'new_outcome' => $outcome->value,
                    'outcome_version' => $lesson->outcome_version,
                    'booking_id' => $lesson->booking_id,
                    'booking_reference' => $lesson->booking?->reference,
                    'booking_meeting_id' => $lesson->booking?->meeting?->id,
                    'attendance_record_id' => $lesson->attendance_record_id,
                ],
            );

            LessonOutcomeOverridden::dispatch($lesson, $previous, $outcome, $reason);

            return new OutcomeFinalizationResult($lesson, applied: true, previousOutcome: $previous);
        });
    }

    private function sanitizeNotes(?string $notes): ?string
    {
        if ($notes === null || trim($notes) === '') {
            return null;
        }

        return mb_substr(trim(strip_tags($notes)), 0, 1000);
    }
}
