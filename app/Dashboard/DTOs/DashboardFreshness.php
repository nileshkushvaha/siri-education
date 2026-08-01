<?php

declare(strict_types=1);

namespace App\Dashboard\DTOs;

use App\Reporting\Enums\ReportDataFreshness;
use Carbon\CarbonImmutable;

/**
 * Freshness for the composed dashboard. The period-scoped composition
 * is cached, so this reports `CachedWithTimestamp` — never `Live` —
 * which is exactly the distinction `ReportDataFreshness`'s own docblock
 * insists on ("No report may claim `Live` while actually reading
 * cached/snapshot data").
 *
 * The Needs Attention feed has its own, much shorter freshness and
 * carries it separately, so urgent current-state counts are never
 * presented with the period section's older timestamp.
 */
final readonly class DashboardFreshness
{
    public function __construct(
        public ReportDataFreshness $freshness,
        public CarbonImmutable $generatedAt,
        public string $reportingTimezone,
        public string $periodLabel,
        public int $ttlSeconds,
    ) {}

    public function label(): string
    {
        return $this->freshness->label();
    }

    /** Human-facing age, used to make a cached figure's staleness explicit. */
    public function generatedAtLabel(): string
    {
        return $this->generatedAt->setTimezone($this->reportingTimezone)->toDayDateTimeString();
    }
}
