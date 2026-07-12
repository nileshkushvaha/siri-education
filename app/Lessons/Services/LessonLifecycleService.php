<?php

declare(strict_types=1);

namespace App\Lessons\Services;

use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\Enums\BookingPaymentStatus;
use App\Booking\Enums\BookingStatus;
use App\Booking\Exceptions\BookingException;
use App\Enums\ActivityActorType;
use App\Lessons\Actions\CreateLessonFromBookingAction;
use App\Lessons\Actions\FinalizeLessonOutcomeAction;
use App\Lessons\Actions\TransitionLessonAction;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonRepositoryInterface;
use App\Lessons\DTOs\OutcomeFinalizationData;
use App\Lessons\Enums\LessonAttendanceStatus;
use App\Lessons\Enums\LessonOutcome;
use App\Lessons\Enums\LessonStatus;
use App\Lessons\Events\LessonCancelled;
use App\Lessons\Events\LessonCompleted;
use App\Lessons\Events\LessonDisputed;
use App\Lessons\Exceptions\LessonException;
use App\Lessons\Exceptions\TerminalLessonOutcomeException;
use App\Models\Booking;
use App\Models\Lesson;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Settings\LessonSettings;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates the lesson lifecycle: creation from a confirmed booking,
 * live/attendance marking, completion (manual and automatic), no-show
 * finalization, disputes, and cancellation. Every status write goes
 * through TransitionLessonAction; every mutation is recorded via
 * AuditTrailService (never activity() directly).
 *
 * Lesson ↔ booking sync: a finalized lesson pushes its outcome onto the
 * still-confirmed parent booking through BookingServiceInterface, so
 * booking events/notifications/audit fire exactly as they do for the
 * admin's manual complete/no-show actions. The reverse direction is
 * handled by the SyncLessonOnBooking* listeners; both sides no-op when
 * the other is already finalized, so no loop is possible.
 */
final class LessonLifecycleService implements LessonLifecycleServiceInterface
{
    private const string LOG_NAME = 'lessons';

    public function __construct(
        private readonly LessonRepositoryInterface $lessons,
        private readonly CreateLessonFromBookingAction $createAction,
        private readonly TransitionLessonAction $transition,
        private readonly FinalizeLessonOutcomeAction $outcomeFinalize,
        private readonly BookingServiceInterface $bookings,
        private readonly AuditTrailService $audit,
        private readonly LessonSettings $settings,
    ) {}

    public function createFromBooking(Booking $booking): ?Lesson
    {
        $existing = $this->lessons->findForBooking($booking);

        if ($existing !== null) {
            return $existing;
        }

        if (! $this->isEligible($booking)) {
            return null;
        }

        $lesson = $this->createAction->execute($booking);

        $this->audit->logSystem(
            self::LOG_NAME,
            'lesson_created',
            sprintf('Lesson scheduled for booking %s.', $booking->reference),
            $lesson,
            ['booking_reference' => $booking->reference],
        );

        return $lesson;
    }

    /**
     * A lesson exists only for a valid confirmed booking: both real
     * participants, a concrete time range, and settled payment terms
     * (free or paid — never a pending/failed/refunded hold). Guest
     * bookings never grow lessons.
     */
    public function isEligible(Booking $booking): bool
    {
        return $booking->status === BookingStatus::Confirmed
            && $booking->attendee_id !== null
            && $booking->host_id !== null
            && $booking->starts_at !== null
            && $booking->ends_at !== null
            && in_array($booking->payment_status, [BookingPaymentStatus::NotRequired, BookingPaymentStatus::Paid], strict: true);
    }

    public function markLive(Lesson $lesson, ?User $actor = null): Lesson
    {
        $lesson = $this->transition->execute($lesson, LessonStatus::Live);

        $this->log($actor, 'lesson_live', sprintf('Lesson %s marked live.', $lesson->id), $lesson);

        return $lesson;
    }

    public function markStudentAttendance(Lesson $lesson, LessonAttendanceStatus $status, ?User $actor = null, bool $override = false): Lesson
    {
        return $this->markAttendance($lesson, 'student', $status, $actor, $override);
    }

    public function markInstructorAttendance(Lesson $lesson, LessonAttendanceStatus $status, ?User $actor = null, bool $override = false): Lesson
    {
        return $this->markAttendance($lesson, 'instructor', $status, $actor, $override);
    }

    public function complete(Lesson $lesson, ?User $actor = null, ?string $notes = null, bool $override = false): Lesson
    {
        // Duplicate completion is a no-op, not an error — completion may
        // race between instructor confirmation, admin action, and the sweep.
        if ($lesson->status === LessonStatus::Completed) {
            return $lesson;
        }

        if (! $override) {
            $this->assertCompletable($lesson);
        }

        // Status transition and outcome finalization commit atomically —
        // a rejected outcome (e.g. technical issue) rolls the status back.
        $lesson = DB::transaction(function () use ($lesson, $actor, $notes, $override): Lesson {
            $lesson = $this->transition->execute($lesson, LessonStatus::Completed, [
                'completed_at' => now(),
                'completed_by' => $actor?->id,
                'completion_notes' => $notes,
                // Confirming completion asserts the lesson was delivered —
                // parties never flagged either way count as attended.
                'student_attendance_status' => $this->attendedUnlessNoShow($lesson->student_attendance_status),
                'instructor_attendance_status' => $this->attendedUnlessNoShow($lesson->instructor_attendance_status),
            ]);

            $this->finalizeOutcome($lesson, LessonOutcome::Completed, 'lesson_completed', $actor, $notes, allowEarlyCompletion: $override);

            return $lesson;
        });

        $this->log($actor, 'lesson_completed', sprintf('Lesson %s completed.', $lesson->id), $lesson);

        $this->syncBookingOutcome($lesson, BookingStatus::Completed);

        LessonCompleted::dispatch($lesson);

        return $lesson;
    }

    public function finalizeNoShow(Lesson $lesson, ?User $actor = null): Lesson
    {
        $outcome = $this->noShowOutcome($lesson);

        if ($outcome === null) {
            throw new LessonException('Neither party is marked as a no-show — record attendance first.');
        }

        $lesson = DB::transaction(function () use ($lesson, $outcome, $actor): Lesson {
            $lesson = $this->transition->execute($lesson, $outcome);

            $this->finalizeOutcome($lesson, match ($outcome) {
                LessonStatus::StudentNoShow => LessonOutcome::StudentNoShow,
                LessonStatus::InstructorNoShow => LessonOutcome::InstructorNoShow,
                default => LessonOutcome::BothAbsent,
            }, 'no_show_recorded', $actor);

            return $lesson;
        });

        $this->log($actor, 'lesson_no_show', sprintf('Lesson %s finalized as %s.', $lesson->id, $outcome->label()), $lesson);

        $this->syncBookingOutcome($lesson, BookingStatus::NoShow);

        return $lesson;
    }

    public function dispute(Lesson $lesson, User $actor, string $reason): Lesson
    {
        $lesson = $this->transition->execute($lesson, LessonStatus::Disputed, [
            'dispute_reason' => $reason,
        ]);

        $this->audit->logUser(
            $actor,
            self::LOG_NAME,
            'lesson_disputed',
            sprintf('Lesson %s disputed.', $lesson->id),
            $lesson,
            ['reason' => $reason],
        );

        LessonDisputed::dispatch($lesson);

        return $lesson;
    }

    public function cancel(Lesson $lesson, ?User $actor = null, ?string $reason = null): Lesson
    {
        $lesson = DB::transaction(function () use ($lesson, $actor, $reason): Lesson {
            $lesson = $this->transition->execute($lesson, LessonStatus::Cancelled, [
                'metadata' => [...($lesson->metadata ?? []), ...array_filter(['cancellation_reason' => $reason])],
            ]);

            $this->finalizeOutcome($lesson, LessonOutcome::Cancelled, 'lesson_cancelled', $actor, $reason);

            return $lesson;
        });

        $this->log($actor, 'lesson_cancelled', sprintf('Lesson %s cancelled.', $lesson->id), $lesson);

        LessonCancelled::dispatch($lesson);

        return $lesson;
    }

    public function autoCompleteDue(): int
    {
        if (! $this->settings->auto_complete_enabled) {
            return 0;
        }

        // Phase 17B: while the evidence-driven finalizer owns automation
        // (lessons:finalize-due), the lenient sweep defers — exactly one
        // automated finalization policy runs at a time.
        if ($this->settings->automated_finalization_enabled) {
            return 0;
        }

        $cutoff = now()->subMinutes($this->settings->auto_complete_grace_minutes);
        $finalized = 0;

        foreach ($this->lessons->openEndedBefore($cutoff) as $lesson) {
            try {
                if ($this->noShowOutcome($lesson) !== null) {
                    $this->finalizeNoShow($lesson);
                } elseif ($this->meetsCompletionRequirements($lesson)) {
                    $this->autoComplete($lesson);
                } else {
                    // Attendance confirmation is required and missing —
                    // leave the lesson open for a manual decision.
                    continue;
                }
                $finalized++;
            } catch (LessonException) {
                // A concurrent transition beat the sweep — skip, next run re-checks.
            }
        }

        return $finalized;
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /**
     * Completion rules (no admin override): the lesson must have ended,
     * and any attendance confirmation the platform requires must be
     * recorded. Admin/booking-driven completion passes override instead.
     *
     * @throws LessonException
     */
    private function assertCompletable(Lesson $lesson): void
    {
        if ($lesson->ends_at->isFuture()) {
            throw new LessonException('The lesson has not ended yet — an admin override is required to complete it early.');
        }

        if (! $this->meetsCompletionRequirements($lesson)) {
            throw new LessonException('Required attendance has not been confirmed for this lesson.');
        }
    }

    private function meetsCompletionRequirements(Lesson $lesson): bool
    {
        if ($this->settings->require_instructor_completion
            && $lesson->instructor_attendance_status !== LessonAttendanceStatus::Attended) {
            return false;
        }

        if ($this->settings->require_student_attendance
            && $lesson->student_attendance_status !== LessonAttendanceStatus::Attended) {
            return false;
        }

        return true;
    }

    private function autoComplete(Lesson $lesson): Lesson
    {
        // Attendance statuses are deliberately left as recorded — an
        // auto-completion asserts "nobody reported a problem in time",
        // not verified attendance. A reported technical issue makes the
        // outcome action throw, rolling the transition back so the sweep
        // can never auto-complete a broken meeting.
        $lesson = DB::transaction(function () use ($lesson): Lesson {
            $lesson = $this->transition->execute($lesson, LessonStatus::Completed, [
                'completed_at' => now(),
                'auto_completed_at' => now(),
            ]);

            $this->finalizeOutcome($lesson, LessonOutcome::Completed, 'auto_completed', null);

            return $lesson;
        });

        $this->audit->logSystem(
            self::LOG_NAME,
            'lesson_auto_completed',
            sprintf('Lesson %s auto-completed %d minutes after its end time.', $lesson->id, $this->settings->auto_complete_grace_minutes),
            $lesson,
        );

        $this->syncBookingOutcome($lesson, BookingStatus::Completed);

        LessonCompleted::dispatch($lesson);

        return $lesson;
    }

    private function markAttendance(Lesson $lesson, string $party, LessonAttendanceStatus $status, ?User $actor, bool $override = false): Lesson
    {
        if ($status === LessonAttendanceStatus::Pending) {
            throw new LessonException('Attendance can only be marked as attended or no-show.');
        }

        if (! $lesson->status->isOpen()) {
            throw new LessonException('Attendance can only be marked while the lesson is scheduled or live.');
        }

        // A no-show can only be recorded once the lesson is meaningfully
        // late — admin override bypasses (H: "admin can override").
        if ($status === LessonAttendanceStatus::NoShow
            && ! $override
            && now()->isBefore($lesson->starts_at->addMinutes($this->settings->no_show_grace_minutes))) {
            throw new LessonException(sprintf(
                'A no-show can only be recorded %d minutes after the lesson start.',
                $this->settings->no_show_grace_minutes,
            ));
        }

        DB::transaction(function () use ($lesson, $party, $status): void {
            $lesson->fill([
                "{$party}_attendance_status" => $status,
                "{$party}_attended_at" => $status === LessonAttendanceStatus::Attended ? now() : null,
            ])->save();
        });

        $this->log(
            $actor,
            'lesson_attendance_marked',
            sprintf('Lesson %s: %s marked as %s.', $lesson->id, $party, $status->label()),
            $lesson,
            ['party' => $party, 'attendance' => $status->value],
        );

        return $lesson;
    }

    /**
     * Record the equivalent finalized outcome for a lifecycle-driven
     * finalization (Phase 17A). Idempotent through the action; a
     * dispute resolution moving the status away from an already-terminal
     * outcome is tolerated — correcting the outcome itself requires the
     * explicit LessonOutcomeServiceInterface::override() API.
     */
    private function finalizeOutcome(Lesson $lesson, LessonOutcome $outcome, string $reasonCode, ?User $actor, ?string $notes = null, bool $allowEarlyCompletion = false): void
    {
        try {
            $this->outcomeFinalize->execute($lesson, new OutcomeFinalizationData(
                outcome: $outcome,
                reasonCode: $reasonCode,
                byType: $actor !== null ? ActivityActorType::User : ActivityActorType::System,
                actor: $actor,
                notes: $notes,
                allowEarlyCompletion: $allowEarlyCompletion,
            ));
        } catch (TerminalLessonOutcomeException) {
            // The outcome was already finalized differently (e.g. a
            // dispute resolved after completion) — the recorded outcome
            // stands until an authorized admin override corrects it.
        }
    }

    private function attendedUnlessNoShow(LessonAttendanceStatus $current): LessonAttendanceStatus
    {
        return $current === LessonAttendanceStatus::NoShow ? $current : LessonAttendanceStatus::Attended;
    }

    private function noShowOutcome(Lesson $lesson): ?LessonStatus
    {
        $student = $lesson->student_attendance_status === LessonAttendanceStatus::NoShow;
        $instructor = $lesson->instructor_attendance_status === LessonAttendanceStatus::NoShow;

        return match (true) {
            $student && $instructor => LessonStatus::BothNoShow,
            $student => LessonStatus::StudentNoShow,
            $instructor => LessonStatus::InstructorNoShow,
            default => null,
        };
    }

    /**
     * Push the lesson's outcome onto a still-confirmed parent booking so
     * booking events, notifications, and audit fire through the engine.
     * Tolerant by design: an already-finalized booking is left alone.
     */
    private function syncBookingOutcome(Lesson $lesson, BookingStatus $outcome): void
    {
        $booking = $lesson->booking()->first();

        if ($booking === null || ! $booking->status->canTransitionTo($outcome)) {
            return;
        }

        try {
            $outcome === BookingStatus::Completed
                ? $this->bookings->complete($booking)
                : $this->bookings->markNoShow($booking);
        } catch (BookingException) {
            // The booking moved concurrently — its own lifecycle wins there;
            // the lesson outcome above remains authoritative here.
        }
    }

    /** @param array<string, mixed> $properties */
    private function log(?User $actor, string $event, string $description, Lesson $lesson, array $properties = []): void
    {
        $actor !== null
            ? $this->audit->logUser($actor, self::LOG_NAME, $event, $description, $lesson, $properties)
            : $this->audit->logSystem(self::LOG_NAME, $event, $description, $lesson, $properties);
    }
}
