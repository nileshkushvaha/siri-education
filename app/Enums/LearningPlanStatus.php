<?php

declare(strict_types=1);

namespace App\Enums;

enum LearningPlanStatus: string
{
    case Draft = 'draft';
    case AwaitingAssessment = 'awaiting_assessment';
    case Active = 'active';
    case Paused = 'paused';
    case ReviewDue = 'review_due';
    case Completed = 'completed';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::AwaitingAssessment => 'Awaiting assessment',
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::ReviewDue => 'Review due',
            self::Completed => 'Completed',
            self::Archived => 'Archived',
        };
    }

    public function isWritable(): bool
    {
        return ! in_array($this, [self::Completed, self::Archived], true);
    }
}
