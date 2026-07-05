<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\InstructorStatus;
use App\Events\ActivityCreated;
use App\Models\User;
use App\Notifications\Instructor\InstructorProfileStatusNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Sends a notification to the instructor when their profile status is
 * updated to approved, rejected, or published. Runs alongside
 * NotifyAdminsOnActivity — both listen to the same ActivityCreated event.
 */
final class NotifyInstructorOnProfileActivity implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function handle(ActivityCreated $event): void
    {
        $activity = $event->activity;

        if ($activity->log_name !== 'instructor') {
            return;
        }

        $statusEvent = match ($activity->event) {
            'profile_approved' => InstructorStatus::Approved,
            'profile_rejected' => InstructorStatus::Rejected,
            'profile_active' => InstructorStatus::Active,
            default => null,
        };

        if ($statusEvent === null) {
            return;
        }

        $instructor = $activity->subject;

        if (! $instructor instanceof User) {
            return;
        }

        $instructor->notify(new InstructorProfileStatusNotification($statusEvent));
    }
}
