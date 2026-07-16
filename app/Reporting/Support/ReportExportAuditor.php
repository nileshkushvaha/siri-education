<?php

declare(strict_types=1);

namespace App\Reporting\Support;

use App\Models\User;
use App\Reporting\DTOs\ExportRequestContext;
use App\Reporting\Enums\ReportExportResult;
use App\Services\AuditTrailService;

/**
 * Defines how a later Phase 18 export slice will record sensitive
 * exports (Phase 18B §20) — routed exclusively through the existing
 * `AuditTrailService`, never a second audit mechanism and never a raw
 * Spatie Activitylog helper call. No export is actually executed by
 * this phase; this class exists so the contract and its metadata shape
 * are settled and tested before any report performs a real export.
 */
final class ReportExportAuditor
{
    private const string LOG_NAME = 'reporting';

    public function __construct(private readonly AuditTrailService $audit) {}

    public function recordRequested(User $admin, ExportRequestContext $context): void
    {
        $this->record($admin, ReportExportResult::Requested, $context, null);
    }

    public function recordCompleted(User $admin, ExportRequestContext $context, ?int $rowCount = null): void
    {
        $this->record($admin, ReportExportResult::Completed, $context, $rowCount);
    }

    public function recordFailed(User $admin, ExportRequestContext $context): void
    {
        $this->record($admin, ReportExportResult::Failed, $context, null);
    }

    private function record(User $admin, ReportExportResult $result, ExportRequestContext $context, ?int $rowCount): void
    {
        $properties = $context->toAuditMetadata();
        $properties['result'] = $result->value;

        if ($rowCount !== null) {
            $properties['row_count'] = $rowCount;
        }

        $this->audit->logUser(
            $admin,
            self::LOG_NAME,
            sprintf('report_export_%s', $result->value),
            sprintf('Report "%s" export %s.', $context->reportKey, $result->value),
            null,
            $properties,
        );
    }
}
