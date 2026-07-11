<?php

declare(strict_types=1);

namespace App\Lessons\Exceptions;

use App\Lessons\Enums\LessonStatus;

final class InvalidLessonTransitionException extends LessonException
{
    public static function between(LessonStatus $from, LessonStatus $to): self
    {
        return new self(sprintf(
            'A lesson cannot transition from "%s" to "%s".',
            $from->value,
            $to->value,
        ));
    }
}
