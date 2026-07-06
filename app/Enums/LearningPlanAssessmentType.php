<?php

declare(strict_types=1);

namespace App\Enums;

enum LearningPlanAssessmentType: string
{
    case Initial = 'initial';
    case Progress = 'progress';
    case Final = 'final';

    public function label(): string
    {
        return match ($this) {
            self::Initial => 'Initial',
            self::Progress => 'Progress',
            self::Final => 'Final',
        };
    }
}
