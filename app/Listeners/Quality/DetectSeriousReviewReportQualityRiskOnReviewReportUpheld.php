<?php

declare(strict_types=1);

namespace App\Listeners\Quality;

use App\Quality\Actions\DetectSeriousReviewReportQualityRiskAction;
use App\Reviews\Events\ReviewReportUpheld;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Thin trigger — all detection logic lives in DetectSeriousReviewReportQualityRiskAction. */
final class DetectSeriousReviewReportQualityRiskOnReviewReportUpheld implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly DetectSeriousReviewReportQualityRiskAction $detect,
    ) {}

    public function handle(ReviewReportUpheld $event): void
    {
        $this->detect->execute($event->report);
    }
}
