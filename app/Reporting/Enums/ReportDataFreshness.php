<?php

declare(strict_types=1);

namespace App\Reporting\Enums;

/**
 * How current a report's/metric's data is (Phase 18B §17). Every report
 * and metric definition declares one of these — a live-query report may
 * use the request timestamp as its freshness marker; a cached/snapshot
 * report must show the last successful refresh time instead. No report
 * may claim `Live` while actually reading cached/snapshot data.
 */
enum ReportDataFreshness: string
{
    case Live = 'live';
    case CachedWithTimestamp = 'cached_with_timestamp';
    case SnapshotWithTimestamp = 'snapshot_with_timestamp';
    case AsynchronousExport = 'asynchronous_export';

    public function label(): string
    {
        return match ($this) {
            self::Live => 'Live query',
            self::CachedWithTimestamp => 'Cached',
            self::SnapshotWithTimestamp => 'Snapshot',
            self::AsynchronousExport => 'Asynchronous export',
        };
    }
}
