<?php

declare(strict_types=1);

namespace App\Reporting\Support;

use App\Reporting\Enums\ReportingPeriodPreset;
use App\Reporting\Exceptions\InvalidReportingPeriodException;
use App\Reporting\ValueObjects\ReportingPeriod;

/**
 * Resolves a {@see ReportingPeriod} from untrusted string input.
 *
 * Report pages bind their period state to the query string, so raw
 * values now arrive from the URL rather than only from a Livewire
 * select. `ReportingPeriod::custom()` deliberately THROWS on an
 * end-before-start pair or a range wider than
 * `ReportingPeriod::MAX_CUSTOM_RANGE_DAYS` — correct for a programmatic
 * caller, but a hand-edited URL must not be able to 500 a report page.
 *
 * This resolver is the one place that turns those exceptions into a
 * safe fallback to the page's own default preset. It never widens a
 * period beyond what `ReportingPeriod` itself permits: validation stays
 * in the value object, and only the failure handling lives here.
 */
final class ReportPeriodResolver
{
    /**
     * @param  string|null  $preset  Raw preset value; an unrecognised value falls back to `$default`.
     * @param  string|null  $customStart  Raw `Y-m-d`; ignored unless the preset is Custom and both dates are present.
     * @param  string|null  $customEnd  Raw `Y-m-d`.
     */
    public static function resolve(
        ?string $preset,
        ?string $customStart = null,
        ?string $customEnd = null,
        ReportingPeriodPreset $default = ReportingPeriodPreset::Last30Days,
        ?string $timezone = null,
    ): ReportingPeriod {
        $resolvedTimezone = ReportingTimezoneResolver::resolve($timezone);
        $resolvedPreset = ReportingPeriodPreset::tryFrom((string) $preset) ?? $default;

        if ($resolvedPreset !== ReportingPeriodPreset::Custom) {
            return ReportingPeriod::forPreset($resolvedPreset, $resolvedTimezone);
        }

        // Custom without a complete date pair is an incomplete selection,
        // not an error — the user is mid-edit, or the URL carried only
        // `period=custom`. Fall back rather than throwing.
        if (blank($customStart) || blank($customEnd)) {
            return ReportingPeriod::forPreset($default, $resolvedTimezone);
        }

        try {
            return ReportingPeriod::custom($customStart, $customEnd, $resolvedTimezone);
        } catch (InvalidReportingPeriodException) {
            // End before start, or wider than MAX_CUSTOM_RANGE_DAYS. The
            // value object already rejected it; we degrade to the default
            // preset instead of surfacing a 500 from a URL value.
            return ReportingPeriod::forPreset($default, $resolvedTimezone);
        } catch (\InvalidArgumentException) {
            // Unparseable date string (e.g. `?start=banana`).
            return ReportingPeriod::forPreset($default, $resolvedTimezone);
        }
    }

    /**
     * True when the supplied custom pair would be rejected by
     * `ReportingPeriod::custom()`. Lets a page tell the user its custom
     * range was ignored instead of silently showing different data.
     */
    public static function customRangeIsInvalid(?string $preset, ?string $customStart, ?string $customEnd): bool
    {
        if (ReportingPeriodPreset::tryFrom((string) $preset) !== ReportingPeriodPreset::Custom) {
            return false;
        }

        if (blank($customStart) || blank($customEnd)) {
            return false;
        }

        try {
            ReportingPeriod::custom($customStart, $customEnd, ReportingTimezoneResolver::resolve());

            return false;
        } catch (InvalidReportingPeriodException|\InvalidArgumentException) {
            return true;
        }
    }
}
