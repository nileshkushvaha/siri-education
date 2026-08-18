<?php

declare(strict_types=1);

namespace App\Messaging\Safety\Enums;

/**
 * WHERE a finding came from, and therefore how much it can be trusted.
 *
 * This is the column that keeps probabilistic findings distinguishable
 * from deterministic ones forever. A `Deterministic` finding is a fact —
 * this message contains an email address, and an admin can verify it in
 * one glance. An `AiIntent` or `AiModeration` finding is an opinion,
 * carries a confidence, and may simply be wrong. They live in one table
 * so an admin has one place to look, and are never presented as the
 * same kind of claim.
 */
enum MessageSafetySource: string
{
    /** Pattern rules — free, instant, explainable, no provider involved. */
    case Deterministic = 'deterministic';

    /** Structured generation, used only for intent no pattern can express. */
    case AiIntent = 'ai_intent';

    /** The provider's moderation classifier, run only on a reported message. */
    case AiModeration = 'ai_moderation';

    public function label(): string
    {
        return match ($this) {
            self::Deterministic => 'Automatic rule',
            self::AiIntent => 'AI intent analysis',
            self::AiModeration => 'AI safety classifier',
        };
    }

    public function isProbabilistic(): bool
    {
        return $this !== self::Deterministic;
    }
}
