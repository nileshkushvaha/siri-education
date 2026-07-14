<?php

declare(strict_types=1);

namespace App\Quality\Enums;

/**
 * What kind of signal produced this alert. `SuspiciousReviewPattern`
 * is reserved vocabulary — no detector in this phase produces it
 * (same precedent as `Withdrawn` being reserved on `ReviewReportStatus`
 * until a future phase implements it).
 */
enum InstructorQualityAlertType: string
{
    case SingleLowRating = 'single_low_rating';
    case RepeatedLowRatings = 'repeated_low_ratings';
    case InstructorNoShow = 'instructor_no_show';
    case RepeatedInstructorNoShows = 'repeated_instructor_no_shows';
    case RepeatedInstructorCancellations = 'repeated_instructor_cancellations';
    case SeriousReviewReport = 'serious_review_report';
    case SuspiciousReviewPattern = 'suspicious_review_pattern';

    public function label(): string
    {
        return match ($this) {
            self::SingleLowRating => 'Single Low Rating',
            self::RepeatedLowRatings => 'Repeated Low Ratings',
            self::InstructorNoShow => 'Instructor No-Show',
            self::RepeatedInstructorNoShows => 'Repeated Instructor No-Shows',
            self::RepeatedInstructorCancellations => 'Repeated Instructor Cancellations',
            self::SeriousReviewReport => 'Serious Review Report',
            self::SuspiciousReviewPattern => 'Suspicious Review Pattern',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::SingleLowRating => 'gray',
            self::RepeatedLowRatings => 'warning',
            self::InstructorNoShow => 'warning',
            self::RepeatedInstructorNoShows => 'danger',
            self::RepeatedInstructorCancellations => 'warning',
            self::SeriousReviewReport => 'danger',
            self::SuspiciousReviewPattern => 'gray',
        };
    }

    /** Whether this type represents a rolling-window threshold crossing (vs. a single-occurrence signal). */
    public function isRepeated(): bool
    {
        return in_array($this, [
            self::RepeatedLowRatings,
            self::RepeatedInstructorNoShows,
            self::RepeatedInstructorCancellations,
        ], strict: true);
    }
}
