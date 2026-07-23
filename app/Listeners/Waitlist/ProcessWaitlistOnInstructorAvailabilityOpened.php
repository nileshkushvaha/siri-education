<?php

declare(strict_types=1);

namespace App\Listeners\Waitlist;

use App\Waitlist\Events\InstructorAvailabilityOpened;
use App\Waitlist\Services\WaitlistService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Thin trigger for the two direct-availability triggers (a new/
 * reactivated TeacherAvailability window, or vacation mode ending).
 * All processing logic lives in WaitlistService.
 */
final class ProcessWaitlistOnInstructorAvailabilityOpened implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly WaitlistService $waitlist,
    ) {}

    public function handle(InstructorAvailabilityOpened $event): void
    {
        $this->waitlist->processAvailabilityOpening($event->instructor, $event->reason, $event->triggerId);
    }
}
