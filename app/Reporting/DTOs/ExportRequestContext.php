<?php

declare(strict_types=1);

namespace App\Reporting\DTOs;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Shared export authorization/audit metadata contract (Phase 18B §19-20).
 * No file is generated and no download route exists yet — this is only
 * the metadata shape a later Phase 18 slice's actual CSV export will
 * build and pass to `ReportExportAuditor`. Carries ids and a safe
 * filter summary only — never a raw filter value that could be a
 * secret, and never a hydrated model.
 */
final readonly class ExportRequestContext
{
    public function __construct(
        public string $reportKey,
        public int $requestedByUserId,
        public string $requiredExportPermission,
        public bool $sensitive,
        public bool $financial,
        public string $reportingTimezone,
        public CarbonImmutable $periodStart,
        public CarbonImmutable $periodEndExclusive,
        /** @var array<string, mixed> safe scalars only */
        public array $safeFilterSummary,
        public CarbonImmutable $generatedAt,
        public string $format,
        public int $maxRows,
        /** One reference shared by the requested/completed/failed audit events AND embedded in the generated CSV (Phase 18I §9). */
        public string $correlationReference,
    ) {}

    public static function forExport(
        string $reportKey,
        User $requestedBy,
        string $requiredExportPermission,
        bool $sensitive,
        bool $financial,
        string $reportingTimezone,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEndExclusive,
        array $safeFilterSummary,
        string $format = 'csv',
        int $maxRows = 50000,
        ?string $correlationReference = null,
    ): self {
        return new self(
            reportKey: $reportKey,
            requestedByUserId: $requestedBy->id,
            requiredExportPermission: $requiredExportPermission,
            sensitive: $sensitive,
            financial: $financial,
            reportingTimezone: $reportingTimezone,
            periodStart: $periodStart,
            periodEndExclusive: $periodEndExclusive,
            safeFilterSummary: $safeFilterSummary,
            generatedAt: CarbonImmutable::now(),
            format: $format,
            maxRows: $maxRows,
            correlationReference: $correlationReference ?? (string) Str::uuid(),
        );
    }

    /** @return array<string, mixed> never contains secrets or raw personal data — safe for AuditTrailService properties. */
    public function toAuditMetadata(): array
    {
        return [
            'correlation_reference' => $this->correlationReference,
            'report_key' => $this->reportKey,
            'requested_by' => $this->requestedByUserId,
            'sensitive' => $this->sensitive,
            'financial' => $this->financial,
            'reporting_timezone' => $this->reportingTimezone,
            'period_start' => $this->periodStart->toIso8601String(),
            'period_end_exclusive' => $this->periodEndExclusive->toIso8601String(),
            'filters' => $this->safeFilterSummary,
            'format' => $this->format,
            'max_rows' => $this->maxRows,
            'generated_at' => $this->generatedAt->toIso8601String(),
        ];
    }
}
