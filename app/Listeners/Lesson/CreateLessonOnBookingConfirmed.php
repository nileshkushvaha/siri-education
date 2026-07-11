<?php

declare(strict_types=1);

namespace App\Listeners\Lesson;

use App\Booking\Events\BookingConfirmed;
use App\Lessons\Contracts\LessonLifecycleServiceInterface;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The only automatic lesson-creation trigger: BookingConfirmed fires
 * exactly once per booking (BookingService::confirm() and the
 * auto-confirm path in request()). LessonLifecycleService re-checks
 * eligibility and idempotency, so — like the meeting listener beside
 * it — this is a thin, safe trigger, not a decision point.
 */
final class CreateLessonOnBookingConfirmed implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly LessonLifecycleServiceInterface $lessons,
    ) {}

    public function handle(BookingConfirmed $event): void
    {
        $this->lessons->createFromBooking($event->booking);
    }
}
