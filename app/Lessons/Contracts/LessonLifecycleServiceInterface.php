<?php

declare(strict_types=1);

namespace App\Lessons\Contracts;

use App\Lessons\Enums\LessonAttendanceStatus;
use App\Lessons\Exceptions\LessonException;
use App\Models\Booking;
use App\Models\Lesson;
use App\Models\User;

/**
 * Single entry point for every lesson status/attendance mutation.
 * Controllers, Livewire components, Filament actions, listeners, and
 * commands all call this — status changes are never scattered.
 */
interface LessonLifecycleServiceInterface
{
    /**
     * Create the lesson for a confirmed booking. Idempotent (returns
     * the existing lesson) and eligibility-guarded (returns null for
     * bookings that must never grow a lesson: pending, unpaid,
     * cancelled, expired, or guest bookings).
     */
    public function createFromBooking(Booking $booking): ?Lesson;

    /** Whether the booking may ever grow a lesson (confirmed, real participants, settled payment terms). */
    public function isEligible(Booking $booking): bool;

    /** @throws LessonException */
    public function markLive(Lesson $lesson, ?User $actor = null): Lesson;

    /**
     * $override (admin/system) bypasses the no-show grace period.
     *
     * @throws LessonException
     */
    public function markStudentAttendance(Lesson $lesson, LessonAttendanceStatus $status, ?User $actor = null, bool $override = false): Lesson;

    /** @throws LessonException */
    public function markInstructorAttendance(Lesson $lesson, LessonAttendanceStatus $status, ?User $actor = null, bool $override = false): Lesson;

    /**
     * Final completed state — instructor confirmation or admin action.
     * Idempotent: completing an already-completed lesson is a no-op.
     * Without $override the lesson must have ended and any required
     * attendance confirmations (LessonSettings) must be recorded.
     *
     * @throws LessonException
     */
    public function complete(Lesson $lesson, ?User $actor = null, ?string $notes = null, bool $override = false): Lesson;

    /**
     * Derive and persist the no-show outcome from the recorded
     * attendance statuses (at least one party must be marked no-show).
     *
     * @throws LessonException
     */
    public function finalizeNoShow(Lesson $lesson, ?User $actor = null): Lesson;

    /** @throws LessonException */
    public function dispute(Lesson $lesson, User $actor, string $reason): Lesson;

    /** @throws LessonException */
    public function cancel(Lesson $lesson, ?User $actor = null, ?string $reason = null): Lesson;

    /**
     * Auto-complete (or no-show-finalize, when attendance says so)
     * every open lesson past the grace period. Returns the number of
     * lessons finalized.
     */
    public function autoCompleteDue(): int;
}
