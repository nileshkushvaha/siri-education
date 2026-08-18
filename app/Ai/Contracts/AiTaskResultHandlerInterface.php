<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\DTOs\AiTaskDescriptor;
use App\Ai\DTOs\AiTaskResult;

/**
 * The output counterpart of AiTaskInputResolverInterface: where a
 * finished run's validated result goes.
 *
 * ExecuteAiTaskJob deliberately knows nothing about what an answer is
 * FOR, so without this a queued run could only ever be telemetry.
 * Implementations live in the DOMAIN that owns the outcome — a quality
 * insight handler belongs under app/Quality, never under app/Ai — which
 * keeps "what may be persisted, and under whose rules" with the team
 * that owns the record.
 *
 * Called for every terminal outcome, success or failure, so a domain
 * record can never be left waiting forever on an answer that will not
 * arrive. It must be idempotent: a job released and re-run may hand the
 * same correlation id a second result.
 */
interface AiTaskResultHandlerInterface
{
    public function handle(AiTaskDescriptor $descriptor, AiTaskResult $result): void;
}
