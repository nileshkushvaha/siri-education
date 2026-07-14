<?php

declare(strict_types=1);

namespace App\Quality\Enums;

/**
 * A recorded recommendation only — this phase never automatically
 * suspends an instructor, hides a profile, removes availability,
 * changes compensation, or alters marketplace ranking. Every value
 * here is a note for a human to act on later.
 */
enum InstructorQualityAlertResolutionAction: string
{
    case NoAction = 'no_action';
    case MonitorInstructor = 'monitor_instructor';
    case ContactInstructor = 'contact_instructor';
    case IssueWarning = 'issue_warning';
    case RequestQualityReview = 'request_quality_review';
    case ReferForSuspensionReview = 'refer_for_suspension_review';

    public function label(): string
    {
        return match ($this) {
            self::NoAction => 'No Action',
            self::MonitorInstructor => 'Monitor Instructor',
            self::ContactInstructor => 'Contact Instructor',
            self::IssueWarning => 'Issue Warning',
            self::RequestQualityReview => 'Request Quality Review',
            self::ReferForSuspensionReview => 'Refer For Suspension Review',
        };
    }
}
