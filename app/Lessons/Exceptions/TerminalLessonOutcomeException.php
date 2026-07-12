<?php

declare(strict_types=1);

namespace App\Lessons\Exceptions;

use App\Lessons\Enums\LessonOutcome;

/** A terminal outcome can never be changed silently — only OverrideLessonOutcomeAction (permission + reason + audit) may correct it. */
final class TerminalLessonOutcomeException extends LessonOutcomeException
{
    public static function between(LessonOutcome $current, LessonOutcome $attempted): self
    {
        return new self(sprintf(
            'The lesson outcome is already finalized as "%s" — changing it to "%s" requires an administrator override with a reason.',
            $current->value,
            $attempted->value,
        ));
    }
}
