<?php

declare(strict_types=1);

namespace App\Enums;

enum LearningGoalType: string
{
    case Academic = 'academic';
    case ExamPreparation = 'exam_preparation';
    case Professional = 'professional';
    case Personal = 'personal';
    case SkillDevelopment = 'skill_development';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Academic => 'Academic',
            self::ExamPreparation => 'Exam preparation',
            self::Professional => 'Professional',
            self::Personal => 'Personal',
            self::SkillDevelopment => 'Skill development',
            self::Other => 'Other',
        };
    }

    public function requiresAcademicLevel(): bool
    {
        return in_array($this, [self::Academic, self::ExamPreparation], true);
    }
}
