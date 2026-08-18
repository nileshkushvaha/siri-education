<?php

declare(strict_types=1);

namespace App\Homework\Copilot\Resolvers;

use App\Ai\Contracts\AiTaskResultHandlerInterface;
use App\Ai\DTOs\AiTaskDescriptor;
use App\Ai\DTOs\AiTaskResult;
use App\Homework\Copilot\Contracts\HomeworkFeedbackDraftRepositoryInterface;
use App\Homework\Copilot\Contracts\HomeworkFeedbackDraftServiceInterface;
use App\Homework\Copilot\DTOs\HomeworkFeedbackDraftData;

/**
 * Where a finished run lands. It stores the draft against its row and
 * does nothing else.
 *
 * In particular it does NOT: write the assignment's feedback, change
 * homework status, set a grade, notify the student, recalculate the
 * learning plan, or mark anything reviewed. Every one of those remains
 * an instructor action through the pre-existing review flow. The
 * strongest statement of the phase's rule is how little this class
 * does.
 */
final class HomeworkFeedbackResultHandler implements AiTaskResultHandlerInterface
{
    public function __construct(
        private readonly HomeworkFeedbackDraftRepositoryInterface $drafts,
        private readonly HomeworkFeedbackDraftServiceInterface $service,
    ) {}

    public function handle(AiTaskDescriptor $descriptor, AiTaskResult $result): void
    {
        $draft = $descriptor->correlationId === null
            ? null
            : $this->drafts->find($descriptor->correlationId);

        if ($draft === null) {
            return;
        }

        if (! $result->succeeded() || $result->data === null) {
            $this->service->markFailed($draft, $result->failureCode?->value, $result->runId ?: null);

            return;
        }

        $this->service->storeResult(
            $draft,
            HomeworkFeedbackDraftData::fromValidated($result->data),
            $result->runId ?: null,
        );
    }
}
