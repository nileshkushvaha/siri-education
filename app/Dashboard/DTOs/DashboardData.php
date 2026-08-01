<?php

declare(strict_types=1);

namespace App\Dashboard\DTOs;

/**
 * The composed, permission-filtered dashboard. Mirrors the nullable-
 * section contract established by
 * `App\Reporting\DTOs\Marketplace\ExecutiveKpiOverviewData`: a section
 * is null (or an empty list) when the viewer lacks the underlying
 * permission, and the composition service omits it BEFORE querying, so
 * restricted data is never fetched and then hidden in the view.
 *
 * This object is assembled from figures other services already
 * calculated. It is not a calculation owner and holds no query of its
 * own.
 */
final readonly class DashboardData
{
    /**
     * @param  list<KpiCard>  $kpis  At most six, enforced by the composition service.
     * @param  list<DashboardChart>  $charts  At most four in the primary area.
     * @param  list<DomainSummary>  $summaries
     * @param  list<ReportLink>  $primaryReports  At most six.
     * @param  list<ReportLink>  $additionalReports  The remainder, behind "View all reports".
     * @param  list<array{label: string, url: string, icon: string, description: string}>  $administrationLinks
     */
    public function __construct(
        public DashboardContext $context,
        public DashboardFreshness $freshness,
        public array $kpis,
        public array $charts,
        public array $summaries,
        public array $primaryReports,
        public array $additionalReports,
        public array $administrationLinks,
        public ?SystemHealthData $systemHealth,
        public ?string $reportingHubUrl,
    ) {}

    /**
     * True when the viewer holds no reporting permission at all — the
     * page then renders a single honest explanation instead of a grid
     * of empty sections.
     */
    public function hasBusinessContent(): bool
    {
        return $this->kpis !== []
            || $this->charts !== []
            || $this->summaries !== [];
    }
}
