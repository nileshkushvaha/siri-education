<?php

declare(strict_types=1);

namespace App\Listeners\Reviews;

use App\Reviews\Contracts\InstructorRatingAggregateServiceInterface;
use App\Reviews\Events\StudentReviewEdited;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Thin trigger — an idempotent safety net behind the synchronous
 * reconcile EditStudentReviewAction already performed under lock. The
 * reconciler recomputes desired state from the review's CURRENT
 * status, so a duplicate/replayed event converges instead of
 * double-adding or double-removing.
 */
final class ReconcileRatingContributionOnStudentReviewEdited implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly InstructorRatingAggregateServiceInterface $ratings,
    ) {}

    public function handle(StudentReviewEdited $event): void
    {
        $this->ratings->reconcile($event->review);
    }
}
