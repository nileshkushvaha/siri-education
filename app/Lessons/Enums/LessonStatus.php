<?php

declare(strict_types=1);

namespace App\Lessons\Enums;

enum LessonStatus: string
{
    case Scheduled = 'scheduled';
    case Live = 'live';
    case Completed = 'completed';
    case StudentNoShow = 'student_no_show';
    case InstructorNoShow = 'instructor_no_show';
    case BothNoShow = 'both_no_show';
    case Cancelled = 'cancelled';
    case Disputed = 'disputed';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::Live => 'Live',
            self::Completed => 'Completed',
            self::StudentNoShow => 'Student No-Show',
            self::InstructorNoShow => 'Instructor No-Show',
            self::BothNoShow => 'Both No-Show',
            self::Cancelled => 'Cancelled',
            self::Disputed => 'Disputed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Scheduled => 'info',
            self::Live => 'warning',
            self::Completed => 'success',
            self::StudentNoShow, self::InstructorNoShow, self::BothNoShow => 'gray',
            self::Cancelled => 'danger',
            self::Disputed => 'danger',
        };
    }

    /** Still awaiting an outcome — the auto-completion sweep only touches these. */
    public function isOpen(): bool
    {
        return match ($this) {
            self::Scheduled, self::Live => true,
            default => false,
        };
    }

    public function isNoShow(): bool
    {
        return match ($this) {
            self::StudentNoShow, self::InstructorNoShow, self::BothNoShow => true,
            default => false,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Cancelled;
    }

    /**
     * Single source of truth for the lesson lifecycle state machine.
     * TransitionLessonAction guards every transition through this method.
     */
    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), strict: true);
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Scheduled => [self::Live, self::Completed, self::StudentNoShow, self::InstructorNoShow, self::BothNoShow, self::Cancelled, self::Disputed],
            self::Live => [self::Completed, self::StudentNoShow, self::InstructorNoShow, self::BothNoShow, self::Cancelled, self::Disputed],
            // Outcomes may still be contested; a dispute is resolved back to Completed or Cancelled.
            self::Completed => [self::Disputed],
            self::StudentNoShow, self::InstructorNoShow, self::BothNoShow => [self::Disputed],
            self::Disputed => [self::Completed, self::Cancelled],
            self::Cancelled => [],
        };
    }
}
