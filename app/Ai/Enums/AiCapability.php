<?php

declare(strict_types=1);

namespace App\Ai\Enums;

/**
 * The four AI capabilities the platform recognises. A capability is a
 * CONTRACT, not a provider feature list: every capability maps to one
 * interface in App\Ai\Contracts, and a provider advertises which ones
 * it implements through AiProviderCapabilities.
 *
 * StructuredGeneration is deliberately separate from TextGeneration
 * rather than a flag on it — structured output is the only shape whose
 * response is schema-validated before it may reach a DTO, and business
 * features are expected to prefer it (see docs/ai/README.md §Structured
 * output). Free-form text stays available for genuinely prose-shaped
 * work (a draft an admin will read and edit), never for anything that
 * feeds a decision.
 *
 * Embedding exists as an extension point only. P0 stores no vectors and
 * adds no vector infrastructure.
 */
enum AiCapability: string
{
    case TextGeneration = 'text_generation';
    case StructuredGeneration = 'structured_generation';
    case Moderation = 'moderation';
    case Embedding = 'embedding';

    public function label(): string
    {
        return match ($this) {
            self::TextGeneration => 'Text generation',
            self::StructuredGeneration => 'Structured generation',
            self::Moderation => 'Moderation',
            self::Embedding => 'Embedding',
        };
    }

    /** The model role a capability defaults to when a prompt does not pin one. */
    public function defaultModelRole(): AiModelRole
    {
        return match ($this) {
            self::TextGeneration, self::StructuredGeneration => AiModelRole::Generation,
            self::Moderation => AiModelRole::Moderation,
            self::Embedding => AiModelRole::Embedding,
        };
    }
}
