<?php

declare(strict_types=1);

namespace App\Reporting\Contracts;

use App\Models\User;
use App\Reporting\DTOs\Marketplace\ExecutiveKpiOverviewData;
use App\Reporting\DTOs\Marketplace\MarketplaceComparisonData;
use App\Reporting\DTOs\Marketplace\MarketplaceDemandData;
use App\Reporting\DTOs\Marketplace\MarketplaceSupplyData;
use App\Reporting\DTOs\Operations\OperationsReportFreshnessData;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\ValueObjects\ReportingPeriod;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Marketplace Supply & Demand (`ViewMarketplaceReports`)
 * and Executive KPI Overview (`ViewExecutiveReports`). Strictly
 * read-only. The executive overview composes existing owning services
 * — each underlying group additionally requires ITS OWN permission and
 * silently stays null without it (finance and compensation groups
 * never query when their permission is absent).
 */
interface MarketplaceExecutiveReportServiceInterface
{
    /** @throws AuthorizationException */
    public function marketplaceSupply(User $user, ReportingPeriod $period, ReportFilters $filters): MarketplaceSupplyData;

    /** @throws AuthorizationException */
    public function marketplaceDemand(User $user, ReportingPeriod $period, ReportFilters $filters): MarketplaceDemandData;

    /** @throws AuthorizationException */
    public function marketplaceComparison(User $user, ReportingPeriod $period, ReportFilters $filters): MarketplaceComparisonData;

    /** @throws AuthorizationException */
    public function executiveOverview(User $user, ReportingPeriod $period, ReportFilters $filters): ExecutiveKpiOverviewData;

    public function canViewMarketplace(User $user): bool;

    public function canViewExecutive(User $user): bool;

    public function freshness(ReportingPeriod $period): OperationsReportFreshnessData;
}
