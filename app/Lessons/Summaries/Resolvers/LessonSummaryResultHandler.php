<?php

declare(strict_types=1);

namespace App\Lessons\Summaries\Resolvers;

use App\Ai\Contracts\AiTaskResultHandlerInterface;
use App\Ai\DTOs\AiTaskDescriptor;
use App\Ai\DTOs\AiTaskResult;
use App\Lessons\Summaries\Contracts\LessonSummaryRepositoryInterface;
use App\Lessons\Summaries\Contracts\LessonSummaryServiceInterface;
use App\Lessons\Summaries\DTOs\LessonSummaryDraftData;

/**
 * Where a finished run lands. It stores the draft against its row and
 * does nothing else.
 *
 * In particular it does NOT: complete the lesson, change its status or
 * outcome, write completion notes, recalculate learning-plan progress,
 * complete a milestone, set a level, or notify anyone. The strongest
 * statement of this phase's rule is how little this class does.
 */
final class LessonSummaryResultHandler implements AiTaskResultHandlerInterface
{
    public function __construct(
        private readonly LessonSummaryRepositoryInterface $summaries,
        private readonly LessonSummaryServiceInterface $service,
    ) {}

    public function handle(AiTaskDescriptor $descriptor, AiTaskResult $result): void
    {
        $summary = $descriptor->correlationId === null
            ? null
            : $this->summaries->find($descriptor->correlationId);

        if ($summary === null) {
            return;
        }

        if (! $result->succeeded() || $result->data === null) {
            $this->service->markFailed($summary, $result->failureCode?->value, $result->runId ?: null);

            return;
        }

        $this->service->storeResult(
            $summary,
            LessonSummaryDraftData::fromValidated($result->data),
            $result->runId ?: null,
        );
    }
}
