<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Instructor application/professional lifecycle — separate from
 * App\Models\User::status (account/login eligibility) and
 * App\Enums\StudentStatus (student-side lifecycle). Lives on
 * UserProfile::instructor_status, nullable — null means the user has
 * never applied to become an instructor.
 *
 * See database/migrations/2026_07_08_100000_expand_instructor_status_lifecycle.php
 * for the backfill of existing rows.
 */
enum InstructorStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case DocumentsPending = 'documents_pending';
    case InterviewRequired = 'interview_required';
    case Approved = 'approved';
    case Active = 'active';
    case Vacation = 'vacation';
    case Suspended = 'suspended';
    case Archived = 'archived';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::UnderReview => 'Under Review',
            self::DocumentsPending => 'Documents Pending',
            self::InterviewRequired => 'Interview Required',
            self::Approved => 'Approved',
            self::Active => 'Active',
            self::Vacation => 'On Vacation',
            self::Suspended => 'Suspended',
            self::Archived => 'Archived',
            self::Rejected => 'Rejected',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Submitted, self::UnderReview => 'info',
            self::DocumentsPending, self::InterviewRequired => 'warning',
            self::Approved => 'primary',
            self::Active => 'success',
            self::Vacation => 'warning',
            self::Suspended => 'danger',
            self::Archived => 'gray',
            self::Rejected => 'danger',
        };
    }

    /**
     * Publicly visible + eligible to receive new bookings. Kept as two
     * states (not Active alone) to preserve existing behavior: an
     * Approved instructor was already bookable before this rename, and
     * tightening that would silently deactivate existing approved
     * accounts.
     *
     * @return list<self>
     */
    public static function bookable(): array
    {
        return [self::Approved, self::Active];
    }

    /** @return list<string> */
    public static function bookableValues(): array
    {
        return array_map(fn (self $status): string => $status->value, self::bookable());
    }

    /**
     * Publicly viewable — a strictly wider set than
     * bookable(). A Vacation instructor's profile must stay visible
     * ("temporarily unavailable", booking disabled) rather than 404/403
     * like an unapproved or suspended instructor's does.
     *
     * @return list<self>
     */
    public static function publiclyVisible(): array
    {
        return [self::Approved, self::Active, self::Vacation];
    }

    /**
     * Statuses waiting on an admin action — Draft is excluded, since an
     * applicant who hasn't submitted yet has nothing for an admin to
     * review.
     *
     * @return list<self>
     */
    public static function needsReview(): array
    {
        return [self::Submitted, self::UnderReview, self::DocumentsPending, self::InterviewRequired];
    }
}
