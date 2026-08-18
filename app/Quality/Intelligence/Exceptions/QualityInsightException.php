<?php

declare(strict_types=1);

namespace App\Quality\Intelligence\Exceptions;

use App\Ai\Enums\AiFailureCode;
use RuntimeException;

/**
 * A quality insight could not be requested. Always carries a message an
 * administrator can act on ("AI is disabled", "the budget is spent") —
 * never a provider message, never a stack detail.
 */
final class QualityInsightException extends RuntimeException
{
    public static function notAnInstructor(): self
    {
        return new self('Quality insights can only be generated for instructors.');
    }

    public static function alreadyRunning(): self
    {
        return new self('An insight for this instructor and period is already being generated.');
    }

    public static function aiUnavailable(AiFailureCode $code): self
    {
        return new self(match ($code) {
            AiFailureCode::FeatureDisabled => 'AI quality insights are turned off. Enable them in Settings → AI Platform.',
            AiFailureCode::NotConfigured => 'The AI provider is not configured. Add credentials in Settings → AI Platform.',
            AiFailureCode::BudgetExceeded => 'The AI spend limit has been reached. Raise it in Settings → AI Platform to continue.',
            default => 'AI is currently unavailable. Please try again later.',
        });
    }
}
