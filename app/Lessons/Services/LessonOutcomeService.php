<?php

declare(strict_types=1);

namespace App\Lessons\Services;

use App\Booking\Contracts\BookingServiceInterface;
use App\Booking\Enums\BookingStatus;
use App\Booking\Exceptions\BookingException;
use App\Enums\ActivityActorType;
use App\Lessons\Actions\DetermineLessonOutcomeAction;
use App\Lessons\Actions\FinalizeLessonOutcomeAction;
use App\Lessons\Actions\OverrideLessonOutcomeAction;
use App\Lessons\Actions\TransitionLessonAction;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\DTOs\LessonOutcomeDetermination;
use App\Lessons\DTOs\OutcomeFinalizationData;
use App\Lessons\DTOs\OutcomeFinalizationResult;
use App\Lessons\Enums\LessonAttendanceStatus;
use App\Lessons\Enums\LessonOutcome;
use App\Lessons\Enums\LessonStatus;
use App\Lessons\Exceptions\LessonException;
use App\Lessons\Exceptions\LessonOutcomeException;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates outcome determination, finalization, and administrator
 * override. The outcome columns are written exclusively by the two
 * outcome actions; the operational LessonStatus and the parent booking
 * are then synchronized through the existing lifecycle engine, so its
 * guards, audit, events (LessonCompleted → earnings), and booking
 * notifications keep firing unchanged.
 */
final class LessonOutcomeService implements LessonOutcomeServiceInterface
{
    public function __construct(
        private readonly DetermineLessonOutcomeAction $determineAction,
        private readonly FinalizeLessonOutcomeAction $finalizeAction,
        private readonly OverrideLessonOutcomeAction $overrideAction,
        private readonly TransitionLessonAction $transition,
        private readonly LessonLifecycleServiceInterface $lifecycle,
        private readonly BookingServiceInterface $bookings,
    ) {}

    public function determine(Lesson $lesson): LessonOutcomeDetermination
    {
        return $this->determineAction->execute($lesson);
    }

    public function finalize(Lesson $lesson, ?LessonOutcome $outcome = null, ?User $actor = null, ?string $notes = null): OutcomeFinalizationResult
    {
        if ($actor !== null && ! $actor->can('complete', $lesson)) {
            throw new AuthorizationException('You may not finalize the outcome of this lesson.');
        }

        $determination = $this->determine($lesson);
        $target = $outcome ?? $determination->outcome;

        if ($target === LessonOutcome::Pending) {
            throw new LessonOutcomeException('The lesson is not ready for an outcome yet (it has not ended).');
        }

        $data = new OutcomeFinalizationData(
            outcome: $target,
            reasonCode: $target === $determination->outcome ? $determination->reasonCode : 'manual_finalization',
            byType: $actor !== null ? ActivityActorType::User : ActivityActorType::System,
            actor: $actor,
            notes: $notes,
        );

        return DB::transaction(function () use ($lesson, $data, $actor, $notes, $determination): OutcomeFinalizationResult {
            $result = $this->finalizeAction->execute($lesson, $data);

            if ($result->applied) {
                $this->syncLifecycle($result->lesson, $data->outcome, $actor, $notes, $determination);
            }

            return $result;
        });
    }

    public function override(Lesson $lesson, User $admin, LessonOutcome $outcome, string $reason, ?string $notes = null): OutcomeFinalizationResult
    {
        if (! $admin->can('overrideOutcome', $lesson)) {
            throw new AuthorizationException('You may not override lesson outcomes.');
        }

        return DB::transaction(function () use ($lesson, $admin, $outcome, $reason, $notes): OutcomeFinalizationResult {
            $result = $this->overrideAction->execute($lesson, $admin, $outcome, $reason, $notes);

            if ($result->applied) {
                $this->forceStatus($result->lesson, $outcome, $admin, $reason);
                $this->syncBooking($result->lesson, $outcome);
            }

            return $result;
        });
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /**
     * Push the finalized outcome onto the operational lesson lifecycle.
     * The lifecycle methods re-enter FinalizeLessonOutcomeAction, which
     * no-ops on the already-finalized outcome — no loop, no double event.
     */
    private function syncLifecycle(Lesson $lesson, LessonOutcome $outcome, ?User $actor, ?string $notes, LessonOutcomeDetermination $determination): void
    {
        match ($outcome) {
            LessonOutcome::Completed => $this->lifecycle->complete($lesson, $actor, $notes, override: true),
            LessonOutcome::StudentNoShow,
            LessonOutcome::InstructorNoShow,
            LessonOutcome::BothAbsent => $this->syncNoShow($lesson, $outcome, $actor, $determination),
            LessonOutcome::TechnicalIssue => $this->parkAsDisputed($lesson),
            LessonOutcome::Cancelled => $this->lifecycle->cancel($lesson, $actor, 'Lesson outcome finalized as cancelled.'),
            LessonOutcome::Pending => null,
        };
    }

    private function syncNoShow(Lesson $lesson, LessonOutcome $outcome, ?User $actor, LessonOutcomeDetermination $determination): void
    {
        // finalizeNoShow derives the status from the per-party marks —
        // make sure the absent parties are marked before delegating.
        if ($outcome !== LessonOutcome::InstructorNoShow
            && $lesson->student_attendance_status === LessonAttendanceStatus::Pending) {
            $lesson = $this->lifecycle->markStudentAttendance($lesson, LessonAttendanceStatus::NoShow, $actor, override: true);
        }

        if ($outcome !== LessonOutcome::StudentNoShow
            && $lesson->instructor_attendance_status === LessonAttendanceStatus::Pending) {
            $lesson = $this->lifecycle->markInstructorAttendance($lesson, LessonAttendanceStatus::NoShow, $actor, override: true);
        }

        if ($outcome === LessonOutcome::StudentNoShow
            && $lesson->instructor_attendance_status === LessonAttendanceStatus::Pending
            && $determination->instructorQualifies) {
            $lesson = $this->lifecycle->markInstructorAttendance($lesson, LessonAttendanceStatus::Attended, $actor, override: true);
        }

        if ($outcome === LessonOutcome::InstructorNoShow
            && $lesson->student_attendance_status === LessonAttendanceStatus::Pending
            && $determination->studentQualifies) {
            $lesson = $this->lifecycle->markStudentAttendance($lesson, LessonAttendanceStatus::Attended, $actor, override: true);
        }

        $this->lifecycle->finalizeNoShow($lesson, $actor);
    }

    /**
     * A technical issue parks the lesson in Disputed for a human
     * decision — the auto-completion sweep never touches disputed
     * lessons, so automatic completion is structurally impossible.
     */
    private function parkAsDisputed(Lesson $lesson): void
    {
        if ($lesson->status === LessonStatus::Disputed || ! $lesson->status->canTransitionTo(LessonStatus::Disputed)) {
            return;
        }

        $this->transition->execute($lesson, LessonStatus::Disputed, [
            'dispute_reason' => 'A technical issue was reported for this lesson.',
        ]);
    }

    /**
     * Admin override: force the operational status to match the
     * corrected outcome, then tolerantly nudge the parent booking —
     * an already-finalized booking is left alone (its financial
     * correction belongs to a later phase).
     */
    private function forceStatus(Lesson $lesson, LessonOutcome $outcome, User $admin, string $reason): void
    {
        $target = $outcome->lessonStatus();

        if ($target === null || $lesson->status === $target) {
            return;
        }

        $extra = match ($outcome) {
            LessonOutcome::Completed => [
                'completed_at' => $lesson->completed_at ?? now(),
                'completed_by' => $admin->id,
            ],
            LessonOutcome::TechnicalIssue => ['dispute_reason' => $reason],
            LessonOutcome::Cancelled => [
                'metadata' => [...($lesson->metadata ?? []), 'cancellation_reason' => $reason],
            ],
            default => [],
        };

        $this->transition->execute($lesson, $target, $extra, force: true);
    }

    private function syncBooking(Lesson $lesson, LessonOutcome $outcome): void
    {
        $target = match (true) {
            $outcome === LessonOutcome::Completed => BookingStatus::Completed,
            $outcome->isNoShow() => BookingStatus::NoShow,
            default => null,
        };

        $booking = $lesson->booking()->first();

        if ($target === null || $booking === null || ! $booking->status->canTransitionTo($target)) {
            return;
        }

        try {
            $target === BookingStatus::Completed
                ? $this->bookings->complete($booking)
                : $this->bookings->markNoShow($booking);
        } catch (BookingException|LessonException) {
            // The booking moved concurrently — its own lifecycle wins there.
        }
    }
}
