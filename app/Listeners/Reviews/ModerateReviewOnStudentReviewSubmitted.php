<?php

declare(strict_types=1);

namespace App\Listeners\Reviews;

use App\Reviews\Actions\ModerateSubmittedReviewAction;
use App\Reviews\Events\StudentReviewSubmitted;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Thin trigger — all moderation logic lives in ModerateSubmittedReviewAction. */
final class ModerateReviewOnStudentReviewSubmitted implements ShouldQueue
{
    public string $queue = 'notifications';

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(
        private readonly ModerateSubmittedReviewAction $moderate,
    ) {}

    public function handle(StudentReviewSubmitted $event): void
    {
        $this->moderate->execute($event->review);
    }
}
