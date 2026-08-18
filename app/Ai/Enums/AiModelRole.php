<?php

declare(strict_types=1);

namespace App\Ai\Enums;

/**
 * A model ROLE, never a model name. Business code and prompt
 * definitions only ever ask for a role; AiModelResolver turns the role
 * into the concrete model configured in AiSettings.
 *
 * This is the mechanism behind "never hardcode model names in business
 * code" — swapping the generation model for the whole platform is a
 * settings change, and an architecture test asserts no model string
 * appears outside the AI module.
 */
enum AiModelRole: string
{
    /** Highest-quality reasoning; the default for insight/summary work. */
    case Generation = 'generation';

    /** Cheaper/faster model for high-volume, low-stakes classification. */
    case Fast = 'fast';

    case Embedding = 'embedding';

    case Moderation = 'moderation';

    public function label(): string
    {
        return match ($this) {
            self::Generation => 'Generation model',
            self::Fast => 'Fast model',
            self::Embedding => 'Embedding model',
            self::Moderation => 'Moderation model',
        };
    }
}
