<?php

declare(strict_types=1);

namespace App\Reporting\Support;

use App\Models\User;
use App\Reporting\DTOs\ExportRequestContext;
use App\Reporting\Enums\ReportExportResult;
use App\Services\AuditTrailService;

/**
 * Records sensitive report exports (SRS §20) — routed exclusively
 * through the existing `AuditTrailService`, never a second audit
 * mechanism and never a raw Spatie Activitylog helper call.
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
