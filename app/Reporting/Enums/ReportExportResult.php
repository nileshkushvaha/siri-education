<?php

declare(strict_types=1);

namespace App\Reporting\Enums;

/** The outcome of an export attempt, for audit purposes (Phase 18B §20). */
enum ReportExportResult: string
{
    case Requested = 'requested';
    case Completed = 'completed';
    case Failed = 'failed';
}
