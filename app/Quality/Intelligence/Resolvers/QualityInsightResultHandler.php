<?php

declare(strict_types=1);

namespace App\Quality\Intelligence\Resolvers;

use App\Ai\Contracts\AiTaskResultHandlerInterface;
use App\Ai\DTOs\AiTaskDescriptor;
use App\Ai\DTOs\AiTaskResult;
use App\Quality\Intelligence\Contracts\QualityInsightRepositoryInterface;
use App\Quality\Intelligence\Contracts\QualityInsightServiceInterface;
use App\Quality\Intelligence\DTOs\QualityInsightData;

/**
 * Where a finished run lands. The AI layer hands over a validated
 * result; this class decides what the domain does with it — which is
 * the boundary the whole design turns on: AI produces data, the domain
 * decides, and the decision here is deliberately the smallest possible
 * one (store it for a human to read).
 *
 * It never acts on the content. No alert is raised, no status changes,
 * no notification fires from a concern the model listed — an insight
 * only ever becomes a row an administrator opens.
 *
 * Failures are recorded too, so a Pending insight always resolves to
 * something an admin can see and understand.
 */
final class QualityInsightResultHandler implements AiTaskResultHandlerInterface
{
    public function __construct(
        private readonly QualityInsightRepositoryInterface $insights,
        private readonly QualityInsightServiceInterface $service,
    ) {}

    public function handle(AiTaskDescriptor $descriptor, AiTaskResult $result): void
    {
        $insight = $descriptor->correlationId === null
            ? null
            : $this->insights->find($descriptor->correlationId);

        if ($insight === null) {
            return;
        }

        if (! $result->succeeded() || $result->data === null) {
            $this->service->markFailed($insight, $result->failureCode?->value, $result->runId ?: null);

            return;
        }

        $this->service->storeResult(
            $insight,
            QualityInsightData::fromValidated($result->data),
            $result->runId ?: null,
        );
    }
}
