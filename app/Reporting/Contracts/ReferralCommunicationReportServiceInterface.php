<?php

declare(strict_types=1);

namespace App\Reporting\Contracts;

use App\Models\User;
use App\Reporting\DTOs\Communication\NotificationActivityData;
use App\Reporting\DTOs\Communication\ReferralActivityData;
use App\Reporting\DTOs\Communication\ReviewQualityRatesData;
use App\Reporting\DTOs\Operations\OperationsReportFreshnessData;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\ValueObjects\ReportingPeriod;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Phase 18G — read-only referral, review-quality-rate and notification
 * reporting (SRS Chapters 16, 17, 18 + 19). Strictly read-only: no
 * reward is credited, no review moderated, no alert resolved, no
 * notification sent or retried, no provider called, no audit row
 * written. Each section authorizes independently: referral →
 * `ViewReferralReports`, review rates → `ViewReviewQualityReports`,
 * notifications → `ViewNotificationReports`. Messaging has no
 * Version 1 domain — no messaging method exists on this contract by
 * design.
 */
interface ReferralCommunicationReportServiceInterface
{
    /** @throws AuthorizationException */
    public function referralActivity(User $user, ReportingPeriod $period, ReportFilters $filters): ReferralActivityData;

    /** @throws AuthorizationException */
    public function reviewQualityRates(User $user, ReportingPeriod $period, ReportFilters $filters): ReviewQualityRatesData;

    /** @throws AuthorizationException */
    public function notificationActivity(User $user, ReportingPeriod $period, ReportFilters $filters): NotificationActivityData;

    public function canViewReferrals(User $user): bool;

    public function canViewReviewQuality(User $user): bool;

    public function canViewNotifications(User $user): bool;

    public function freshness(ReportingPeriod $period): OperationsReportFreshnessData;
}
