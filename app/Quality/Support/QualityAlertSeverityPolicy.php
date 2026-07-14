<?php

declare(strict_types=1);

namespace App\Quality\Support;

use App\Quality\Enums\InstructorQualityAlertSeverity;
use App\Quality\Enums\InstructorQualityAlertType;

/**
 * Centralized alert-type → severity mapping. A single place to look
 * (and change) rather than severity decisions scattered across every
 * detector action.
 */
final class QualityAlertSeverityPolicy
{
    public static function severityFor(InstructorQualityAlertType $type): InstructorQualityAlertSeverity
    {
        return match ($type) {
            InstructorQualityAlertType::SingleLowRating => InstructorQualityAlertSeverity::Low,
            InstructorQualityAlertType::RepeatedLowRatings => InstructorQualityAlertSeverity::Medium,
            InstructorQualityAlertType::InstructorNoShow => InstructorQualityAlertSeverity::Medium,
            InstructorQualityAlertType::RepeatedInstructorNoShows => InstructorQualityAlertSeverity::High,
            InstructorQualityAlertType::RepeatedInstructorCancellations => InstructorQualityAlertSeverity::Medium,
            InstructorQualityAlertType::SeriousReviewReport => InstructorQualityAlertSeverity::High,
            InstructorQualityAlertType::SuspiciousReviewPattern => InstructorQualityAlertSeverity::Medium,
        };
    }
}
