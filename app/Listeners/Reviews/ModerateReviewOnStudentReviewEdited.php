<?php

declare(strict_types=1);

namespace App\Listeners\Reviews;

use App\Reviews\Actions\ModerateSubmittedReviewAction;
use App\Reviews\Events\StudentReviewEdited;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Thin trigger — a clean public edit returns the review to Submitted,
 * and this re-runs the exact same automatic moderation a fresh
 * submission gets. ModerateSubmittedReviewAction itself no-ops for
 * any non-Submitted status (a Flagged or Private edit waits for a
 * human / stays private), so no status check is duplicated here.
 */
final class ModerateReviewOnStudentReviewEdited implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly ModerateSubmittedReviewAction $moderate,
    ) {}

    public function handle(StudentReviewEdited $event): void
    {
        $this->moderate->execute($event->review);
    }
}
