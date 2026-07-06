<?php

declare(strict_types=1);

namespace App\Enums;

enum LearningPlanMilestoneStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
            self::Skipped => 'Skipped',
        };
    }
}
