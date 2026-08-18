<?php

declare(strict_types=1);

namespace App\Ai\DTOs;

use App\Ai\Enums\AiCapability;

/**
 * A provider's static, declared shape. AiProviderResolver reads this
 * instead of ever branching on a provider name — the same rule the
 * payout domain follows with PayoutProviderCapabilities.
 */
final readonly class AiProviderCapabilities
{
    public function __construct(
        public bool $textGeneration = false,
        public bool $structuredGeneration = false,
        public bool $moderation = false,
        public bool $embedding = false,
    ) {}

    public function supports(AiCapability $capability): bool
    {
        return match ($capability) {
            AiCapability::TextGeneration => $this->textGeneration,
            AiCapability::StructuredGeneration => $this->structuredGeneration,
            AiCapability::Moderation => $this->moderation,
            AiCapability::Embedding => $this->embedding,
        };
    }
}
