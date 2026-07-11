<?php

declare(strict_types=1);

namespace App\Listeners\Lesson;

use App\Booking\Enums\BookingStatus;
use App\Booking\Events\BookingCompleted;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonRepositoryInterface;
use App\Lessons\Enums\LessonAttendanceStatus;
use App\Models\Lesson;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Keeps the lesson in step when an admin finalizes the booking directly
 * (BookingCompleted covers both Completed and NoShow — read status).
 * Loop-safe: lesson-driven completion fires this same event, but the
 * lesson is already finalized by then, so it no-ops.
 */
final class SyncLessonOnBookingCompleted implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly LessonRepositoryInterface $repository,
        private readonly LessonLifecycleServiceInterface $lessons,
    ) {}

    public function handle(BookingCompleted $event): void
    {
        $lesson = $this->repository->findForBooking($event->booking);

        if ($lesson === null || ! $lesson->status->isOpen()) {
            return;
        }

        // The booking was finalized by an admin (or the engine) — that is
        // the override; the lesson must follow regardless of grace/attendance rules.
        $event->booking->status === BookingStatus::NoShow
            ? $this->finalizeNoShow($lesson)
            : $this->lessons->complete($lesson, override: true);
    }

    private function finalizeNoShow(Lesson $lesson): void
    {
        // A booking-level no-show carries no party detail; in this
        // 1-to-1 tutoring flow it has always meant the attendee
        // (student) — record that unless attendance already says more.
        if ($lesson->student_attendance_status !== LessonAttendanceStatus::NoShow
            && $lesson->instructor_attendance_status !== LessonAttendanceStatus::NoShow) {
            $this->lessons->markStudentAttendance($lesson, LessonAttendanceStatus::NoShow, override: true);
        }

        $this->lessons->finalizeNoShow($lesson);
    }
}
