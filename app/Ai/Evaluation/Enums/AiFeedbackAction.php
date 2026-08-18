<?php

declare(strict_types=1);

namespace App\Ai\Evaluation\Enums;

/**
 * The explicit verdict a reviewer gives on one AI output.
 *
 * Deliberately binary. A five-point scale invites an average, an
 * average invites a target, and a target invites tuning a prompt to
 * move a number rather than to be more useful. "Was this worth your
 * time?" is the question that actually predicts whether a feature
 * should survive.
 */
enum AiFeedbackAction: string
{
    case Helpful = 'helpful';
    case NotHelpful = 'not_helpful';

    public function label(): string
    {
        return match ($this) {
            self::Helpful => 'Helpful',
            self::NotHelpful => 'Not helpful',
        };
    }

    public function isPositive(): bool
    {
        return $this === self::Helpful;
    }
}
