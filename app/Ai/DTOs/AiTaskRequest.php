<?php

declare(strict_types=1);

namespace App\Ai\DTOs;

use App\Ai\Enums\AiCapability;
use App\Ai\Enums\AiFeature;
use App\Ai\Enums\AiModelRole;

/**
 * An AI task ready to execute: a descriptor plus the resolved prompt
 * variables. Lives only in memory for the duration of one execution —
 * never serialized, never queued, never logged (AiLogger's allowlist
 * has no room for it), never written to ai_runs.
 *
 * $variables are substituted into the registered prompt template.
 * Values are stringified as-is: sanitizing or truncating student
 * content is the OWNING DOMAIN's decision, not this module's, since
 * only that domain knows what is safe to send.
 */
final readonly class AiTaskRequest
{
    /** @param array<string, string> $variables */
    public function __construct(
        public AiFeature $feature,
        public AiCapability $capability,
        public string $promptKey,
        public array $variables = [],
        public ?string $promptVersion = null,
        public ?string $subjectType = null,
        public ?string $subjectId = null,
        public ?int $requestedBy = null,
        public ?AiModelRole $modelRole = null,
    ) {}

    /** @param array<string, string> $variables */
    public static function fromDescriptor(AiTaskDescriptor $descriptor, array $variables): self
    {
        return new self(
            feature: $descriptor->feature,
            capability: $descriptor->capability,
            promptKey: $descriptor->promptKey,
            variables: $variables,
            promptVersion: $descriptor->promptVersion,
            subjectType: $descriptor->subjectType,
            subjectId: $descriptor->subjectId,
            requestedBy: $descriptor->requestedBy,
            modelRole: $descriptor->modelRole,
        );
    }
}
