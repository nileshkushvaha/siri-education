<?php

declare(strict_types=1);

namespace App\Homework\Enums;

enum HomeworkResourceStatus: string
{
    case Active = 'active';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Archived => 'Archived',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Archived => 'gray',
        };
    }
}
