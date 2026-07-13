<?php

declare(strict_types=1);

namespace App\Reviews\Enums;

/**
 * What, if anything, a report resolution does to the underlying
 * review's moderation status. Every non-`NoAction` value delegates to
 * the matching `ReviewModerationService` method — this enum never
 * drives a direct `LessonReview.status` write itself.
 */
enum ReviewReportResolutionAction: string
{
    case NoAction = 'no_action';
    case HideReview = 'hide_review';
    case RejectReview = 'reject_review';
    case ArchiveReview = 'archive_review';
    case RestoreReview = 'restore_review';

    public function label(): string
    {
        return match ($this) {
            self::NoAction => 'No Action',
            self::HideReview => 'Hide Review',
            self::RejectReview => 'Reject Review',
            self::ArchiveReview => 'Archive Review',
            self::RestoreReview => 'Restore Review',
        };
    }
}
