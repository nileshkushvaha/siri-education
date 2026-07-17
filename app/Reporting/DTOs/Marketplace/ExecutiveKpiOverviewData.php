<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Marketplace;

use App\Reporting\DTOs\Communication\NotificationActivityData;
use App\Reporting\DTOs\Communication\ReviewQualityRatesData;
use App\Reporting\DTOs\Engagement\InstructorActivitySummaryData;
use App\Reporting\DTOs\Engagement\InstructorLifecycleSummaryData;
use App\Reporting\DTOs\Engagement\StudentEngagementSummaryData;
use App\Reporting\DTOs\Finance\InstructorFinancialSummaryData;
use App\Reporting\DTOs\Finance\PaymentFinancialSummaryData;
use App\Reporting\DTOs\Finance\RefundSummaryData;
use App\Reporting\DTOs\Finance\WalletFinancialSummaryData;
use App\Reporting\DTOs\Learning\HomeworkAnalyticsData;
use App\Reporting\DTOs\Learning\LearningPlanAnalyticsData;
use App\Reporting\DTOs\Learning\MilestoneReviewAnalyticsData;
use App\Reporting\DTOs\Operations\BookingOperationsSummaryData;
use App\Reporting\DTOs\Operations\LessonOutcomeSummaryData;

/**
 * Phase 18H — the executive overview is a COMPOSITION, never a second
 * calculation owner: every group is the exact readonly DTO produced by
 * its existing owning report service (Phases 18C–18G), fetched through
 * that service's own public contract so its authorization and filter
 * restriction run unchanged. A group is null when the viewer lacks the
 * underlying permission — the restricted section's queries never even
 * execute. No recognized revenue, net revenue, margin, LTV, retention,
 * utilization or ranking figure exists anywhere in this graph; unlike
 * currencies are never combined.
 */
final readonly class ExecutiveKpiOverviewData
{
    public function __construct(
        public ?StudentEngagementSummaryData $students,
        public ?InstructorLifecycleSummaryData $instructorLifecycle,
        public ?InstructorActivitySummaryData $instructorActivity,
        public ?BookingOperationsSummaryData $bookings,
        public ?LessonOutcomeSummaryData $lessons,
        public ?LearningPlanAnalyticsData $learningPlans,
        public ?HomeworkAnalyticsData $homework,
        public ?MilestoneReviewAnalyticsData $milestonesReviews,
        public ?ReviewQualityRatesData $quality,
        public ?PaymentFinancialSummaryData $payments,
        public ?WalletFinancialSummaryData $wallet,
        public ?RefundSummaryData $refunds,
        public ?InstructorFinancialSummaryData $instructorFinancials,
        public ?NotificationActivityData $notifications,
    ) {}
}
