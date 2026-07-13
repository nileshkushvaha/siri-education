<?php

declare(strict_types=1);

namespace App\Listeners\Reviews;

use App\Reviews\Contracts\InstructorRatingAggregateServiceInterface;
use App\Reviews\Events\StudentReviewPublished;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Thin trigger — all reconciliation logic lives in InstructorRatingAggregateService. */
final class ReconcileRatingContributionOnStudentReviewPublished implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly InstructorRatingAggregateServiceInterface $ratings,
    ) {}

    public function handle(StudentReviewPublished $event): void
    {
        $this->ratings->reconcile($event->review);
    }
}
