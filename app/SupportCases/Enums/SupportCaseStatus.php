<?php

declare(strict_types=1);

namespace App\SupportCases\Enums;

/**
 * SRS §25.9-25.10 defines a longer lifecycle (Open, Assigned, In
 * Review, Waiting for User, Escalated, Resolved, Closed, Reopened,
 * Duplicate, Cancelled, On Hold). Phase 31 deliberately implements the
 * subset the phase brief specified — Open, InProgress (folding
 * "Assigned"/"In Review" into one working state), WaitingForUser,
 * Escalated (kept because §25.40 "Case Status Lifecycle" is an
 * explicit Critical-priority requirement and §25.18/§25.40 "Case
 * Escalation" is explicit High-priority), Resolved, and Closed.
 * Duplicate/Cancelled/On Hold and a distinct "Reopened" state are
 * deferred — reopening is supported as a transition back to Open
 * (§25.33/§25.40 "Case Reopen"), not as its own status. See the Phase
 * 31 final report for this scope note.
 *
 * canTransitionTo()/allowedTransitions() is the single source of
 * truth state machine — TransitionSupportCaseStatusAction is the sole
 * writer of the status column.
 */
enum SupportCaseStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case WaitingForUser = 'waiting_for_user';
    case Escalated = 'escalated';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::InProgress => 'In Progress',
            self::WaitingForUser => 'Waiting for User',
            self::Escalated => 'Escalated',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'gray',
            self::InProgress => 'info',
            self::WaitingForUser => 'warning',
            self::Escalated => 'danger',
            self::Resolved => 'success',
            self::Closed => 'success',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Closed;
    }

    public function isReopenable(): bool
    {
        return in_array($this, [self::Resolved, self::Closed], strict: true);
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), strict: true);
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Open => [self::InProgress, self::Escalated, self::Resolved],
            self::InProgress => [self::WaitingForUser, self::Escalated, self::Resolved],
            self::WaitingForUser => [self::InProgress, self::Escalated, self::Resolved],
            self::Escalated => [self::InProgress, self::WaitingForUser, self::Resolved],
            self::Resolved => [self::Closed, self::Open],
            self::Closed => [self::Open],
        };
    }
}
