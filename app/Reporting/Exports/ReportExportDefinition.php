<?php

declare(strict_types=1);

namespace App\Reporting\Exports;

/**
 * One code-defined CSV export dataset (Phase 18I §5). Data-only: the
 * row provider is resolved by ReportCsvExporter from the stable
 * `key` via an exhaustive match over the EXISTING report-service
 * contracts — never a user-supplied column, closure or query. Columns
 * are fixed here; there is no column selection, no dynamic SQL and no
 * export-only calculation anywhere in the export path.
 *
 * @param  list<string>  $headers  stable machine-readable data headers
 */
final readonly class ReportExportDefinition
{
    public function __construct(
        public string $key,
        public string $reportKey,
        public string $label,
        public string $exportPermission,
        public bool $financial,
        public bool $sensitive,
        public array $headers,
        public int $maxRows,
    ) {}
}
