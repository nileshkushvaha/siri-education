<?php

declare(strict_types=1);

namespace App\Listeners\Quality;

use App\Quality\Actions\ReevaluateInstructorQualityAlertAction;
use App\Quality\Enums\QualityAlertSourceType;
use App\Reviews\Events\StudentReviewArchived;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Thin trigger — an archived review's signal may no longer be valid; flags any active alert for reevaluation without deleting it. */
final class ReevaluateQualityAlertOnStudentReviewArchived implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly ReevaluateInstructorQualityAlertAction $reevaluate,
    ) {}

    public function handle(StudentReviewArchived $event): void
    {
        $this->reevaluate->execute(QualityAlertSourceType::LessonReview, $event->review->id);
    }
}
