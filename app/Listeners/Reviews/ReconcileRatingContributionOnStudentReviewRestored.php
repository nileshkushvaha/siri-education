<?php

declare(strict_types=1);

namespace App\Listeners\Reviews;

use App\Reviews\Contracts\InstructorRatingAggregateServiceInterface;
use App\Reviews\Events\StudentReviewRestored;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Thin trigger — all reconciliation logic lives in InstructorRatingAggregateService. */
final class ReconcileRatingContributionOnStudentReviewRestored implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly InstructorRatingAggregateServiceInterface $ratings,
    ) {}

    public function handle(StudentReviewRestored $event): void
    {
        $this->ratings->reconcile($event->review);
    }
}
