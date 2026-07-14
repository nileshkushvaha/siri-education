<?php

declare(strict_types=1);

namespace App\Listeners\Quality;

use App\Lessons\Events\LessonOutcomeFinalized;
use App\Quality\Actions\DetectInstructorNoShowQualityRiskAction;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Thin trigger — all detection logic lives in DetectInstructorNoShowQualityRiskAction. */
final class DetectInstructorNoShowQualityRiskOnLessonOutcomeFinalized implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly DetectInstructorNoShowQualityRiskAction $detect,
    ) {}

    public function handle(LessonOutcomeFinalized $event): void
    {
        $this->detect->execute($event->lesson);
    }
}
