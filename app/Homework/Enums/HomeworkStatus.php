<?php

declare(strict_types=1);

namespace App\Homework\Enums;

enum HomeworkStatus: string
{
    case Pending = 'pending';
    case Submitted = 'submitted';
    case Graded = 'graded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Submitted => 'Submitted',
            self::Graded => 'Graded',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Submitted => 'info',
            self::Graded => 'success',
        };
    }
}
