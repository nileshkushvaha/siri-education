<?php

declare(strict_types=1);

namespace App\Messaging\Enums;

/**
 * SRS §17.35-§17.36. `TransitionMessageReportStatusAction`-style
 * guarding is unnecessary here — a report has no branching business
 * rule beyond "reviewed or not"; MessagingService::reviewReport() is
 * the sole writer.
 */
enum MessageReportStatus: string
{
    case Pending = 'pending';
    case UnderReview = 'under_review';
    case ActionTaken = 'action_taken';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::UnderReview => 'Under Review',
            self::ActionTaken => 'Action Taken',
            self::Dismissed => 'Dismissed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::UnderReview => 'info',
            self::ActionTaken => 'danger',
            self::Dismissed => 'gray',
        };
    }

    public function isResolved(): bool
    {
        return in_array($this, [self::ActionTaken, self::Dismissed], true);
    }
}
