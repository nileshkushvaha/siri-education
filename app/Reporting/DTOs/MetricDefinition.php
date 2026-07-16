<?php

declare(strict_types=1);

namespace App\Reporting\DTOs;

use App\Reporting\Enums\MetricUnit;
use App\Reporting\Enums\ReportDataFreshness;
use App\Reporting\Enums\ZeroDenominatorPolicy;
use App\Reporting\Registry\MetricRegistry;

/**
 * A single code-defined metric's metadata (Phase 18B §11) — declares
 * how a KPI is authoritatively defined so two dashboards can never
 * calculate the same named metric two different ways. This phase
 * registers metadata only; the metrics needed by the next operations-
 * reporting phase have no query implementation yet (`calculationOwner`
 * says so explicitly for those).
 *
 * @see MetricRegistry for the Version 1 set.
 */
final readonly class MetricDefinition
{
    /**
     * @param  list<string>  $includedStatuses  authoritative source-enum case names/values this metric counts
     * @param  list<string>  $excludedStatuses  authoritative source-enum case names/values this metric never counts
     * @param  list<string>  $supportedDimensions  e.g. ['country', 'instructor', 'subject']
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public string $sourceDomain,
        public string $timestampField,
        public array $includedStatuses,
        public array $excludedStatuses,
        public MetricUnit $unit,
        public bool $sensitive,
        public bool $financial,
        public string $requiredPermission,
        public array $supportedDimensions,
        public ZeroDenominatorPolicy $zeroDenominatorPolicy,
        public string $timezoneBehavior,
        public ReportDataFreshness $freshness,
        public string $calculationOwner,
    ) {}
}
