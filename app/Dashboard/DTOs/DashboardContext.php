<?php

declare(strict_types=1);

namespace App\Dashboard\DTOs;

use App\Reporting\Filters\ReportFilters;
use App\Reporting\ValueObjects\ReportingPeriod;

/**
 * The dashboard's global filter context: one reporting period (built
 * through `ReportingPeriod`, so its DST-safe UTC boundary and
 * maximum-range validation are the reporting layer's, not ours) plus
 * the optional country dimension.
 *
 * Country is deliberately the ONLY optional global filter. It is the
 * single dimension supported broadly enough across report definitions
 * to apply page-wide; every other dimension belongs to the report that
 * declares it. Sections whose metrics do not support country are
 * labelled global by the composition service rather than silently
 * pretending the filter applied.
 */
final readonly class DashboardContext
{
    public function __construct(
        public ReportingPeriod $period,
        public ?int $countryId,
    ) {}

    /** The shared filter object every report service already accepts. */
    public function filters(): ReportFilters
    {
        return new ReportFilters(
            period: $this->period,
            countryId: $this->countryId,
        );
    }

    public function timezone(): string
    {
        return $this->period->timezone;
    }

    public function periodLabel(): string
    {
        return $this->period->preset->label();
    }

    /**
     * Stable, collision-free fragment for the dashboard cache key. Uses
     * the resolved UTC boundary rather than the preset name so a custom
     * range and an equivalent preset never share an entry, and so
     * "today" naturally rolls over at the reporting timezone's midnight.
     */
    public function cacheFragment(): string
    {
        return sprintf(
            '%s|%s|%s|%s|%s',
            $this->period->preset->value,
            $this->period->startUtc->toIso8601String(),
            $this->period->endUtcExclusive->toIso8601String(),
            $this->period->timezone,
            $this->countryId ?? 'all',
        );
    }

    /**
     * Query parameters carrying this context into a report page.
     * `ReportFilters::toSafeArray()`'s own key names are reused so the
     * dashboard and the reports agree on one vocabulary.
     *
     * @return array<string, string|int>
     */
    public function toQueryParameters(): array
    {
        $parameters = ['period' => $this->period->preset->value];

        if ($this->period->preset->value === 'custom') {
            $parameters['start'] = $this->period->start->toDateString();
            $parameters['end'] = $this->period->end->subDay()->toDateString();
        }

        if ($this->countryId !== null) {
            $parameters['country'] = $this->countryId;
        }

        return $parameters;
    }
}
