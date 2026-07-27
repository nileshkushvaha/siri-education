<?php

declare(strict_types=1);

namespace App\Reporting\DTOs;

use App\Reporting\Enums\ReportCategory;
use App\Reporting\Enums\ReportDataFreshness;
use App\Reporting\Enums\ReportingPeriodPreset;
use App\Reporting\Filters\ReportFilterKey;

/**
 * A single code-defined report's metadata (SRS §10). Definitions
 * are plain data — building or listing one never executes a query,
 * never instantiates a domain service, and never touches the database.
 *
 * `requiredViewPermission` may be stricter than `category.requiredPermission()`
 * (e.g. an instructor-compensation report inside `Finance` requires
 * `ViewInstructorCompensationReports`, not just `ViewFinanceReports`) —
 * the category permission only gates landing-page section visibility.
 */
final readonly class ReportDefinition
{
    /**
     * @param  list<ReportFilterKey>  $supportedFilters
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public ReportCategory $category,
        public string $requiredViewPermission,
        public ?string $requiredExportPermission,
        public bool $sensitive,
        public bool $financial,
        public array $supportedFilters,
        public ReportingPeriodPreset $defaultPeriodPreset,
        public string $dataSourceDomain,
        public ReportDataFreshness $freshness,
        public ?string $routeName,
        public bool $exportAvailable,
        public bool $available,
    ) {}
}
