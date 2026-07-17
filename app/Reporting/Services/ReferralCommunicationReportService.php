<?php

declare(strict_types=1);

namespace App\Reporting\Services;

use App\Models\User;
use App\Reporting\Contracts\ReferralCommunicationReportServiceInterface;
use App\Reporting\Contracts\ReportAccessContextInterface;
use App\Reporting\Contracts\ReportRegistryInterface;
use App\Reporting\DTOs\Communication\NotificationActivityData;
use App\Reporting\DTOs\Communication\ReferralActivityData;
use App\Reporting\DTOs\Communication\ReviewQualityRatesData;
use App\Reporting\DTOs\Operations\OperationsReportFreshnessData;
use App\Reporting\Enums\ReportDataFreshness;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\Repositories\ReferralCommunicationReportsRepository;
use App\Reporting\ValueObjects\ReportingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/** Phase 18G implementation — see the interface for the contract. */
final class ReferralCommunicationReportService implements ReferralCommunicationReportServiceInterface
{
    public function __construct(
        private readonly ReferralCommunicationReportsRepository $repository,
        private readonly ReportAccessContextInterface $access,
        private readonly ReportRegistryInterface $registry,
    ) {}

    public function referralActivity(User $user, ReportingPeriod $period, ReportFilters $filters): ReferralActivityData
    {
        $this->authorize($user, 'referral_activity', 'ViewReferralReports');

        return $this->repository->referralActivity($period, $this->restrict($filters, 'referral_activity'));
    }

    public function reviewQualityRates(User $user, ReportingPeriod $period, ReportFilters $filters): ReviewQualityRatesData
    {
        $this->authorize($user, 'review_quality_analytics', 'ViewReviewQualityReports');

        return $this->repository->reviewQualityRates($period, $this->restrict($filters, 'review_quality_analytics'));
    }

    public function notificationActivity(User $user, ReportingPeriod $period, ReportFilters $filters): NotificationActivityData
    {
        $this->authorize($user, 'notification_delivery', 'ViewNotificationReports');

        return $this->repository->notificationActivity($period);
    }

    public function canViewReferrals(User $user): bool
    {
        return $this->allowed($user, 'referral_activity', 'ViewReferralReports');
    }

    public function canViewReviewQuality(User $user): bool
    {
        return $this->allowed($user, 'review_quality_analytics', 'ViewReviewQualityReports');
    }

    public function canViewNotifications(User $user): bool
    {
        return $this->allowed($user, 'notification_delivery', 'ViewNotificationReports');
    }

    public function freshness(ReportingPeriod $period): OperationsReportFreshnessData
    {
        return new OperationsReportFreshnessData(
            freshness: ReportDataFreshness::Live,
            generatedAt: CarbonImmutable::now(),
            reportingTimezone: $period->timezone,
            periodLabel: $period->label,
        );
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function allowed(User $user, string $reportKey, string $permission): bool
    {
        $definition = $this->registry->find($reportKey);

        return $definition !== null
            && $this->access->canView($user, $definition)
            && $this->hasPermission($user, $permission);
    }

    /** @throws AuthorizationException */
    private function authorize(User $user, string $reportKey, string $permission): void
    {
        if (! $this->allowed($user, $reportKey, $permission)) {
            throw new AuthorizationException('You may not view this report section.');
        }
    }

    private function restrict(ReportFilters $filters, string $reportKey): ReportFilters
    {
        $definition = $this->registry->find($reportKey);

        return $filters->restrictedTo($definition?->supportedFilters ?? []);
    }

    private function hasPermission(User $user, string $permission): bool
    {
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        try {
            return $user->hasPermissionTo($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
