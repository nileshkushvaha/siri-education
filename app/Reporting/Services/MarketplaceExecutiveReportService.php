<?php

declare(strict_types=1);

namespace App\Reporting\Services;

use App\Models\User;
use App\Reporting\Contracts\BookingLessonMeetingOperationsReportServiceInterface;
use App\Reporting\Contracts\FinancialReportsServiceInterface;
use App\Reporting\Contracts\InstructorPerformanceReportServiceInterface;
use App\Reporting\Contracts\LearningAnalyticsReportServiceInterface;
use App\Reporting\Contracts\MarketplaceExecutiveReportServiceInterface;
use App\Reporting\Contracts\ReferralCommunicationReportServiceInterface;
use App\Reporting\Contracts\ReportAccessContextInterface;
use App\Reporting\Contracts\ReportRegistryInterface;
use App\Reporting\Contracts\StudentEngagementReportServiceInterface;
use App\Reporting\DTOs\Marketplace\ExecutiveKpiOverviewData;
use App\Reporting\DTOs\Marketplace\MarketplaceComparisonData;
use App\Reporting\DTOs\Marketplace\MarketplaceDemandData;
use App\Reporting\DTOs\Marketplace\MarketplaceSupplyData;
use App\Reporting\DTOs\Operations\OperationsReportFreshnessData;
use App\Reporting\Enums\ReportDataFreshness;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\Repositories\MarketplaceSupplyDemandRepository;
use App\Reporting\ValueObjects\ReportingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/** Phase 18H implementation — see the interface for the contract. */
final class MarketplaceExecutiveReportService implements MarketplaceExecutiveReportServiceInterface
{
    public function __construct(
        private readonly MarketplaceSupplyDemandRepository $marketplace,
        private readonly ReportAccessContextInterface $access,
        private readonly ReportRegistryInterface $registry,
        private readonly StudentEngagementReportServiceInterface $students,
        private readonly InstructorPerformanceReportServiceInterface $instructors,
        private readonly BookingLessonMeetingOperationsReportServiceInterface $operations,
        private readonly LearningAnalyticsReportServiceInterface $learning,
        private readonly FinancialReportsServiceInterface $finance,
        private readonly ReferralCommunicationReportServiceInterface $communications,
    ) {}

    // ── Marketplace ───────────────────────────────────────────────────────

    public function marketplaceSupply(User $user, ReportingPeriod $period, ReportFilters $filters): MarketplaceSupplyData
    {
        $this->authorizeMarketplace($user);

        return $this->marketplace->supply($this->restrict($filters));
    }

    public function marketplaceDemand(User $user, ReportingPeriod $period, ReportFilters $filters): MarketplaceDemandData
    {
        $this->authorizeMarketplace($user);

        return $this->marketplace->demand($period, $this->restrict($filters));
    }

    public function marketplaceComparison(User $user, ReportingPeriod $period, ReportFilters $filters): MarketplaceComparisonData
    {
        $this->authorizeMarketplace($user);

        return $this->marketplace->comparison($period, $this->restrict($filters));
    }

    // ── Executive (pure composition over existing owners) ─────────────────

    public function executiveOverview(User $user, ReportingPeriod $period, ReportFilters $filters): ExecutiveKpiOverviewData
    {
        if (! $this->canViewExecutive($user)) {
            throw new AuthorizationException('You may not view executive reporting.');
        }

        // Each group runs through its OWNING service so that service's own
        // authorization and filter restriction apply unchanged. A group whose
        // underlying permission is absent stays null — its queries never run.
        $canStudents = $this->students->canView($user);
        $canInstructors = $this->instructors->canView($user);
        $canOperations = $this->operations->canViewBookingLessonSection($user);
        $canLearning = $this->learning->canView($user);
        $canQuality = $this->communications->canViewReviewQuality($user);
        $canPayments = $this->finance->canViewPayments($user);
        $canWallet = $this->finance->canViewWallet($user);
        $canCompensation = $this->finance->canViewInstructorFinancials($user);
        $canNotifications = $this->communications->canViewNotifications($user);

        return new ExecutiveKpiOverviewData(
            students: $canStudents ? $this->students->summary($user, $period, $filters) : null,
            instructorLifecycle: $canInstructors ? $this->instructors->lifecycleSummary($user, $period, $filters) : null,
            instructorActivity: $canInstructors ? $this->instructors->activitySummary($user, $period, $filters) : null,
            bookings: $canOperations ? $this->operations->bookingSummary($user, $period, $filters) : null,
            lessons: $canOperations ? $this->operations->lessonOutcomeSummary($user, $period, $filters) : null,
            learningPlans: $canLearning ? $this->learning->planSummary($user, $period, $filters) : null,
            homework: $canLearning ? $this->learning->homeworkSummary($user, $period, $filters) : null,
            milestonesReviews: $canLearning ? $this->learning->milestoneReviewSummary($user, $period, $filters) : null,
            quality: $canQuality ? $this->communications->reviewQualityRates($user, $period, $filters) : null,
            payments: $canPayments ? $this->finance->paymentSummary($user, $period, $filters) : null,
            wallet: $canWallet ? $this->finance->walletSummary($user, $period, $filters) : null,
            refunds: $canWallet ? $this->finance->refundSummary($user, $period, $filters) : null,
            instructorFinancials: $canCompensation ? $this->finance->instructorFinancialSummary($user, $period, $filters) : null,
            notifications: $canNotifications ? $this->communications->notificationActivity($user, $period, $filters) : null,
        );
    }

    // ── Access ────────────────────────────────────────────────────────────

    public function canViewMarketplace(User $user): bool
    {
        return $this->allowed($user, 'marketplace_supply_demand', 'ViewMarketplaceReports');
    }

    public function canViewExecutive(User $user): bool
    {
        return $this->allowed($user, 'executive_summary', 'ViewExecutiveReports');
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
    private function authorizeMarketplace(User $user): void
    {
        if (! $this->canViewMarketplace($user)) {
            throw new AuthorizationException('You may not view marketplace reporting.');
        }
    }

    private function restrict(ReportFilters $filters): ReportFilters
    {
        $definition = $this->registry->find('marketplace_supply_demand');

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
