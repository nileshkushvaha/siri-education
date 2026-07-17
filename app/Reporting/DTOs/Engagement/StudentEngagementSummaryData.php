<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Engagement;

/**
 * Phase 18D — Student Engagement summary. Definition decisions (§6):
 *
 * - `byAccountStatus` is the CURRENT `user_profiles.student_status`
 *   (registered/active/suspended/archived) — an account attribute,
 *   never an engagement claim.
 * - `engagedInPeriod` is a distinct, explicitly-named reporting
 *   definition (Outcome B): students with ≥1 booking created OR ≥1
 *   lesson finalized `Completed` within the period. It is NOT the
 *   account status and the two are never conflated.
 * - `withoutRecentLearningActivity` (§6.2/6.3): non-suspended,
 *   non-archived student accounts with zero qualifying learning
 *   activity in the period — a deterministic rule, not a predictive
 *   risk score.
 * - No retention metric exists here (§6.4 Outcome C — no authoritative
 *   definition; `lifetimeBookingBuckets` is a plain distribution proxy,
 *   never labeled retention).
 *
 * All counts, never a financial value (§18 — lifetime value, wallet,
 * and revenue are structurally absent).
 *
 * @param  array<string, int>  $byAccountStatus  keyed by StudentStatus::value
 * @param  array<string, int>  $recurringParticipation  keyed by RecurrenceClassifier bucket — distinct students per bucket
 * @param  array<string, int>  $lifetimeBookingBuckets  '0', '1', '2-5', '6-10', '11+'
 */
final readonly class StudentEngagementSummaryData
{
    public function __construct(
        public int $totalStudents,
        public int $newInPeriod,
        public int $verifiedTotal,
        public int $verifiedInPeriod,
        public array $byAccountStatus,
        public int $engagedInPeriod,
        public int $withBookingsInPeriod,
        public int $withCompletedLessonsInPeriod,
        public int $withActiveLearningPlans,
        public array $recurringParticipation,
        public int $withActiveLearningGoals,
        public int $withHomeworkActivityInPeriod,
        public int $withReviewsSubmittedInPeriod,
        public int $withoutRecentLearningActivity,
        public array $lifetimeBookingBuckets,
    ) {}
}
