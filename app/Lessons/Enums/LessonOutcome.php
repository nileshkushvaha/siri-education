<?php

declare(strict_types=1);

namespace App\Lessons\Enums;

/**
 * The finalized lesson outcome — a separate, auditable concept from the
 * operational LessonStatus. Pending is the only non-finalized value;
 * FinalizeLessonOutcomeAction is the sole writer of every other value
 * (OverrideLessonOutcomeAction for admin corrections).
 */
enum LessonOutcome: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case StudentNoShow = 'student_no_show';
    case InstructorNoShow = 'instructor_no_show';
    case BothAbsent = 'both_absent';
    case TechnicalIssue = 'technical_issue';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Completed => 'Completed',
            self::StudentNoShow => 'Student No-Show',
            self::InstructorNoShow => 'Instructor No-Show',
            self::BothAbsent => 'Both Absent',
            self::TechnicalIssue => 'Technical Issue',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Completed => 'success',
            self::StudentNoShow, self::InstructorNoShow, self::BothAbsent => 'warning',
            self::TechnicalIssue => 'danger',
            self::Cancelled => 'danger',
        };
    }

    /** Terminal outcomes may only change through an authorized admin override. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::StudentNoShow, self::InstructorNoShow, self::BothAbsent, self::Cancelled => true,
            self::Pending, self::TechnicalIssue => false,
        };
    }

    public function isNoShow(): bool
    {
        return match ($this) {
            self::StudentNoShow, self::InstructorNoShow, self::BothAbsent => true,
            default => false,
        };
    }

    /**
     * The operational LessonStatus this outcome maps onto. TechnicalIssue
     * parks the lesson in Disputed so the auto-completion sweep can never
     * finalize it; Pending drives no status change.
     */
    public function lessonStatus(): ?LessonStatus
    {
        return match ($this) {
            self::Pending => null,
            self::Completed => LessonStatus::Completed,
            self::StudentNoShow => LessonStatus::StudentNoShow,
            self::InstructorNoShow => LessonStatus::InstructorNoShow,
            self::BothAbsent => LessonStatus::BothNoShow,
            self::TechnicalIssue => LessonStatus::Disputed,
            self::Cancelled => LessonStatus::Cancelled,
        };
    }
}
