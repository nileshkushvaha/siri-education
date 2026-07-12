<?php

declare(strict_types=1);

namespace App\Lessons\Enums;

use App\Models\Lesson;
use App\Models\User;

/** The two parties of a 1-to-1 lesson, keyed to the lessons table column prefixes. */
enum LessonParticipant: string
{
    case Student = 'student';
    case Instructor = 'instructor';

    public function label(): string
    {
        return match ($this) {
            self::Student => 'Student',
            self::Instructor => 'Instructor',
        };
    }

    public function userIdOn(Lesson $lesson): int
    {
        return match ($this) {
            self::Student => $lesson->student_id,
            self::Instructor => $lesson->instructor_id,
        };
    }

    public static function forUser(Lesson $lesson, User $user): ?self
    {
        return match ($user->id) {
            $lesson->student_id => self::Student,
            $lesson->instructor_id => self::Instructor,
            default => null,
        };
    }
}
