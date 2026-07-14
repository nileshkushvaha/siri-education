<?php

declare(strict_types=1);

namespace App\Listeners\Quality;

use App\Quality\Actions\DetectLowRatingQualityRiskAction;
use App\Reviews\Events\StudentReviewPublished;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Thin trigger — all detection logic lives in DetectLowRatingQualityRiskAction. */
final class DetectLowRatingQualityRiskOnStudentReviewPublished implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly DetectLowRatingQualityRiskAction $detect,
    ) {}

    public function handle(StudentReviewPublished $event): void
    {
        $this->detect->execute($event->review);
    }
}
