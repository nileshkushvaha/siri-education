<?php

declare(strict_types=1);

namespace App\Listeners\Quality;

use App\Lessons\Enums\LessonOutcome;
use App\Lessons\Events\LessonOutcomeOverridden;
use App\Quality\Actions\DetectInstructorNoShowQualityRiskAction;
use App\Quality\Actions\ReevaluateInstructorQualityAlertAction;
use App\Quality\Enums\QualityAlertSourceType;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Thin trigger for both directions of a no-show correction: an
 * override that newly classifies a lesson as InstructorNoShow runs
 * detection exactly like a first finalization would (idempotent via
 * the source-keyed fingerprint); an override that corrects a lesson
 * *away* from InstructorNoShow flags any existing alert stale rather
 * than deleting it.
 */
final class ReevaluateQualityAlertOnLessonOutcomeOverridden implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly DetectInstructorNoShowQualityRiskAction $detect,
        private readonly ReevaluateInstructorQualityAlertAction $reevaluate,
    ) {}

    public function handle(LessonOutcomeOverridden $event): void
    {
        if ($event->outcome === LessonOutcome::InstructorNoShow) {
            $this->detect->execute($event->lesson);

            return;
        }

        if ($event->previousOutcome === LessonOutcome::InstructorNoShow) {
            $this->reevaluate->execute(QualityAlertSourceType::Lesson, $event->lesson->id);
        }
    }
}
