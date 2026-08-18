<?php

declare(strict_types=1);

namespace App\Messaging\Safety\Resolvers;

use App\Ai\Contracts\AiTaskResultHandlerInterface;
use App\Ai\DTOs\AiTaskDescriptor;
use App\Ai\DTOs\AiTaskResult;
use App\Messaging\Safety\Contracts\MessageSafetyFindingRepositoryInterface;
use App\Messaging\Safety\Contracts\MessageSafetyServiceInterface;

/**
 * Where a safety classification of a REPORTED message lands.
 *
 * Reads AiTaskResult::$moderation — the neutral DTO P0 defined for the
 * moderation capability and which nothing used until now. Like the
 * intent handler, it records and stops: the report itself already has
 * an admin workflow, and this only adds a classifier's opinion to help
 * triage it.
 */
final class MessageModerationResultHandler implements AiTaskResultHandlerInterface
{
    public function __construct(
        private readonly MessageSafetyFindingRepositoryInterface $findings,
        private readonly MessageSafetyServiceInterface $service,
    ) {}

    public function handle(AiTaskDescriptor $descriptor, AiTaskResult $result): void
    {
        $finding = $descriptor->correlationId === null
            ? null
            : $this->findings->find($descriptor->correlationId);

        if ($finding === null) {
            return;
        }

        $moderation = $result->moderation;

        if (! $result->succeeded() || $moderation === null) {
            $this->service->discardPending($finding, $result->failureCode?->value);

            return;
        }

        $this->service->storeModerationResult(
            $finding,
            $moderation->flagged,
            $moderation->categories,
            $moderation->highestScore,
            $result->runId ?: null,
        );
    }
}
