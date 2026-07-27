<?php

declare(strict_types=1);

namespace App\Reporting\DTOs\Operations;

use App\Reporting\Enums\ReportDataFreshness;
use Carbon\CarbonImmutable;

/** Freshness/timezone metadata every operations report render must display (SRS §17/§16). */
final readonly class OperationsReportFreshnessData
{
    public function __construct(
        public ReportDataFreshness $freshness,
        public CarbonImmutable $generatedAt,
        public string $reportingTimezone,
        public string $periodLabel,
    ) {}
}
