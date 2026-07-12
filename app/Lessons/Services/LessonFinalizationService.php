<?php

declare(strict_types=1);

namespace App\Lessons\Services;

use App\Booking\Enums\BookingStatus;
use App\Lessons\Contracts\LessonAttendanceRepositoryInterface;
use App\Lessons\Contracts\LessonAttendanceServiceInterface;
use App\Lessons\Contracts\LessonFinalizationServiceInterface;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonOutcomeServiceInterface;
use App\Lessons\Contracts\LessonRepositoryInterface;
use App\Lessons\Enums\LessonOutcome;
use App\Models\Lesson;
use App\Settings\LessonSettings;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The evidence-driven automated finalizer (lessons:finalize-due).
 *
 * Never writes outcomes itself: cancelled-booking sync goes through the
 * lifecycle service, attendance sealing through the attendance service,
 * and every outcome through LessonOutcomeService →
 * FinalizeLessonOutcomeAction (row lock, version bump, exactly-once
 * LessonOutcomeFinalized). Safe under concurrent workers — the loser of
 * a race sees a finalized outcome and no-ops — and safe to rerun: a
 * processed lesson leaves the open set and is never picked up again.
 *
 * Ships behind lessons.automated_finalization_enabled (default off).
 * While enabled, the legacy lenient sweep (autoCompleteDue) defers so
 * exactly one automation policy is active at a time.
 */
final class LessonFinalizationService implements LessonFinalizationServiceInterface
{
    public function __construct(
        private readonly LessonRepositoryInterface $lessons,
        private readonly LessonAttendanceRepositoryInterface $attendanceRecords,
        private readonly LessonAttendanceServiceInterface $attendance,
        private readonly LessonOutcomeServiceInterface $outcomes,
        private readonly LessonLifecycleServiceInterface $lifecycle,
        private readonly LessonSettings $settings,
    ) {}

    public function processDue(): int
    {
        if (! $this->settings->automated_finalization_enabled) {
            return 0;
        }

        // Nothing is processable before its evidence window closes, so the
        // pickup cutoff is ends_at + attendance delay; per-outcome delays
        // are gated per lesson below. openEndedBefore() cursors ordered by
        // ends_at — deterministic and never loads the full set into memory.
        $cutoff = now()->subMinutes(max(0, $this->settings->attendance_finalize_delay_minutes));
        $finalized = 0;

        foreach ($this->lessons->openEndedBefore($cutoff)->chunk(max(1, $this->settings->finalize_batch_size)) as $batch) {
            foreach ($batch as $lesson) {
                try {
                    $finalized += $this->process($lesson) ? 1 : 0;
                } catch (Throwable $e) {
                    // One failing lesson never stops the batch; it stays
                    // open and re-enters the next run. Message only — no
                    // provider payloads.
                    Log::warning('lessons:finalize-due — lesson skipped after failure', [
                        'lesson_id' => $lesson->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $finalized;
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function process(Lesson $lesson): bool
    {
        // The cursor row may be stale by the time we get here (another
        // worker, a manual admin action) — re-read before deciding.
        $lesson->refresh();

        if (! $lesson->status->isOpen() || $lesson->hasFinalizedOutcome()) {
            return false;
        }

        // Cancelled bookings are excluded from processing except for the
        // required synchronization to the Cancelled outcome.
        if ($lesson->booking?->status === BookingStatus::Cancelled) {
            $this->lifecycle->cancel($lesson, null, 'Booking cancelled — synchronized by automated finalization.');

            return true;
        }

        $record = $this->attendanceRecords->findForLesson($lesson);

        // Late evidence flag = active administrative hold: a human must
        // reconcile the extra evidence before automation may finalize.
        if ($record?->late_evidence_reported_at !== null) {
            return false;
        }

        // The evidence window is closed (pickup cutoff) — seal the record
        // so the per-party statuses reflect the merged evidence. Idempotent.
        if ($record !== null && ! $record->isFinalized()) {
            $this->attendance->finalize($lesson);
            $lesson->refresh();
        }

        $determination = $this->outcomes->determine($lesson);

        if (! $this->outcomeDue($lesson, $determination->outcome)) {
            return false;
        }

        return $this->outcomes->finalize($lesson)->applied;
    }

    /**
     * Per-outcome timing gates on top of the evidence window: completion
     * honours the existing auto-completion delay, no-shows their own grace
     * periods, and a technical issue holds everything until its reporting
     * window closes.
     */
    private function outcomeDue(Lesson $lesson, LessonOutcome $outcome): bool
    {
        return match ($outcome) {
            LessonOutcome::Completed => $this->minutesPassedSinceEnd($lesson, $this->settings->auto_complete_grace_minutes),
            LessonOutcome::StudentNoShow => $this->minutesPassedSinceEnd($lesson, $this->settings->student_no_show_grace_minutes),
            LessonOutcome::InstructorNoShow => $this->minutesPassedSinceEnd($lesson, $this->settings->instructor_no_show_grace_minutes),
            LessonOutcome::BothAbsent => $this->minutesPassedSinceEnd($lesson, max(
                $this->settings->student_no_show_grace_minutes,
                $this->settings->instructor_no_show_grace_minutes,
            )),
            LessonOutcome::TechnicalIssue => $this->minutesPassedSinceEnd($lesson, $this->settings->technical_issue_window_minutes),
            LessonOutcome::Cancelled => true,
            LessonOutcome::Pending => false,
        };
    }

    private function minutesPassedSinceEnd(Lesson $lesson, int $minutes): bool
    {
        return now()->greaterThanOrEqualTo($lesson->ends_at->addMinutes(max(0, $minutes)));
    }
}
