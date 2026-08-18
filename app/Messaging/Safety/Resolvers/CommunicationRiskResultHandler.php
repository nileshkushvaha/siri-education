<?php

declare(strict_types=1);

namespace App\Messaging\Safety\Resolvers;

use App\Ai\Contracts\AiTaskResultHandlerInterface;
use App\Ai\DTOs\AiTaskDescriptor;
use App\Ai\DTOs\AiTaskResult;
use App\Messaging\Safety\Contracts\MessageSafetyFindingRepositoryInterface;
use App\Messaging\Safety\Contracts\MessageSafetyServiceInterface;
use App\Messaging\Safety\DTOs\CommunicationRiskData;

/**
 * Where an intent analysis lands. It records a finding for a human, and
 * does nothing else.
 *
 * It does NOT: hide the message, restrict the sender, close the
 * conversation, notify anyone, or raise a compliance flag. Escalation
 * to account-level review happens only after an ADMIN confirms a
 * finding, through a deterministic threshold rule — never from a
 * model's own output.
 */
final class CommunicationRiskResultHandler implements AiTaskResultHandlerInterface
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

        if (! $result->succeeded() || $result->data === null) {
            // A failed analysis leaves no finding behind: the platform
            // learned nothing about this message, and a placeholder row
            // would be an accusation with no evidence.
            $this->service->discardPending($finding, $result->failureCode?->value);

            return;
        }

        $this->service->storeIntentResult(
            $finding,
            CommunicationRiskData::fromValidated($result->data),
            $result->runId ?: null,
        );
    }
}
