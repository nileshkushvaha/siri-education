<?php

declare(strict_types=1);

namespace App\Ai\DTOs;

use App\Ai\Contracts\AiTaskInputResolverInterface;
use App\Ai\Contracts\AiTaskResultHandlerInterface;
use App\Ai\Enums\AiCapability;
use App\Ai\Enums\AiFeature;
use App\Ai\Enums\AiModelRole;

/**
 * THE QUEUE-SAFE HALF of an AI task: identifiers only, never content.
 *
 * This separation is the mechanism behind "student content is never
 * persisted by the AI layer". A queued job payload is a database row;
 * if a descriptor carried the homework text or the message body, the
 * jobs table (and failed_jobs, which is retained far longer) would
 * quietly become a second store of minors' private content, outside
 * every retention rule the owning domain enforces.
 *
 * So the descriptor names a $inputResolver instead — a container-
 * resolvable class implementing AiTaskInputResolverInterface, which
 * reads the content from its own domain AT EXECUTION TIME and hands it
 * straight to the provider. If the underlying record was deleted before
 * the job ran, the resolver simply fails and no stale copy exists
 * anywhere.
 *
 * AiRunArchitectureTest asserts that ExecuteAiTaskJob accepts nothing
 * but this DTO.
 */
final readonly class AiTaskDescriptor
{
    /**
     * @param  class-string<AiTaskInputResolverInterface>  $inputResolver
     * @param  class-string<AiTaskResultHandlerInterface>|null  $resultHandler  where the finished result goes; null for a run whose only product is telemetry
     * @param  string|null  $correlationId  the id of the DOMAIN RECORD awaiting this result — an identifier, never content, and the only thing a result handler needs to find its row again
     * @param  string|null  $subjectType  morph alias/class of the record this run is about
     */
    public function __construct(
        public AiFeature $feature,
        public AiCapability $capability,
        public string $promptKey,
        public string $inputResolver,
        public ?string $resultHandler = null,
        public ?string $correlationId = null,
        public ?string $promptVersion = null,
        public ?string $subjectType = null,
        public ?string $subjectId = null,
        public ?int $requestedBy = null,
        public ?AiModelRole $modelRole = null,
    ) {}
}
