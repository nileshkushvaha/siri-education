<?php

declare(strict_types=1);

namespace App\Homework\Copilot\Exceptions;

use App\Ai\Enums\AiFailureCode;
use RuntimeException;

/**
 * An AI feedback draft could not be requested. Always an actionable
 * sentence for the instructor — never a provider message, never a stack
 * detail.
 */
final class HomeworkCopilotException extends RuntimeException
{
    public static function notSubmitted(): self
    {
        return new self('AI feedback drafts are only available once the student has submitted their work.');
    }

    public static function alreadyReviewed(): self
    {
        return new self('This homework has already been reviewed.');
    }

    public static function alreadyGenerating(): self
    {
        return new self('A feedback draft for this submission is already being generated.');
    }

    public static function aiUnavailable(AiFailureCode $code): self
    {
        return new self(match ($code) {
            AiFailureCode::FeatureDisabled => 'The AI homework assistant is currently turned off.',
            AiFailureCode::NotConfigured => 'The AI assistant is not configured yet. Please contact support.',
            AiFailureCode::BudgetExceeded => 'The AI assistant has reached its usage limit for now. Please try again later.',
            default => 'The AI assistant is temporarily unavailable. Please try again later.',
        });
    }
}
