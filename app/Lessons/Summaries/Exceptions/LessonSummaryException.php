<?php

declare(strict_types=1);

namespace App\Lessons\Summaries\Exceptions;

use App\Ai\Enums\AiFailureCode;
use RuntimeException;

/**
 * A lesson summary could not be requested. Always an actionable
 * sentence for the instructor — never a provider message.
 */
final class LessonSummaryException extends RuntimeException
{
    public static function notCompleted(): self
    {
        return new self('Lesson summaries are only available once the lesson is completed.');
    }

    public static function alreadyApproved(): self
    {
        return new self('This lesson already has an approved summary.');
    }

    public static function alreadyGenerating(): self
    {
        return new self('A summary for this lesson is already being generated.');
    }

    public static function nothingToSummarize(): self
    {
        return new self('Add a completion note or topic to this lesson first — there is nothing to summarize yet.');
    }

    public static function aiUnavailable(AiFailureCode $code): self
    {
        return new self(match ($code) {
            AiFailureCode::FeatureDisabled => 'AI lesson summaries are currently turned off.',
            AiFailureCode::NotConfigured => 'The AI assistant is not configured yet. Please contact support.',
            AiFailureCode::BudgetExceeded => 'The AI assistant has reached its usage limit for now. Please try again later.',
            default => 'The AI assistant is temporarily unavailable. Please try again later.',
        });
    }
}
