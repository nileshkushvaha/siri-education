<?php

declare(strict_types=1);

namespace App\Listeners\Lesson;

use App\Booking\Events\BookingCancelled;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use App\Lessons\Contracts\LessonRepositoryInterface;
use App\Lessons\Enums\LessonStatus;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A booking cancelled after confirmation takes its lesson with it.
 * Tolerant: no lesson, or a lesson already finalized, is a no-op.
 */
final class SyncLessonOnBookingCancelled implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly LessonRepositoryInterface $repository,
        private readonly LessonLifecycleServiceInterface $lessons,
    ) {}

    public function handle(BookingCancelled $event): void
    {
        $lesson = $this->repository->findForBooking($event->booking);

        if ($lesson === null || ! $lesson->status->canTransitionTo(LessonStatus::Cancelled)) {
            return;
        }

        $this->lessons->cancel($lesson, reason: 'Parent booking was cancelled.');
    }
}
