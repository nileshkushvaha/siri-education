<?php

declare(strict_types=1);

namespace App\Lessons\Enums;

enum LessonAttendanceStatus: string
{
    case Pending = 'pending';
    case Attended = 'attended';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Attended => 'Attended',
            self::NoShow => 'No-Show',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Attended => 'success',
            self::NoShow => 'danger',
        };
    }
}
