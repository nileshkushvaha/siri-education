<?php

declare(strict_types=1);

namespace App\Reporting\Support;

use App\Reporting\ValueObjects\ReportingPeriod;
use DateTimeZone;

/**
 * TZ-5 (TZ-AUD-010): DST-correct local-day grouping in SQL, without
 * MySQL's timezone tables.
 *
 * The defect it replaces captured the reporting timezone's offset ONCE,
 * at the start of the period, and reused it for every row:
 *
 *     $offset = $period->start->format('P');            // '+00:00'
 *     DATE(CONVERT_TZ(col, '+00:00', $offset))
 *
 * For a fixed-offset zone (Asia/Kolkata, UTC) that is exact. For
 * Europe/London, America/New_York or Australia/Sydney it is exact only
 * until the clocks change: after a transition the offset is an hour
 * stale, so every row within an hour of local midnight is attributed to
 * the wrong local date, quietly, in a number somebody is reading as
 * fact.
 *
 * Named-zone conversion is NOT the fix here. `CONVERT_TZ(col, 'UTC',
 * 'Europe/London')` returns NULL unless MySQL's `mysql.time_zone_name`
 * table is populated, and it is empty on this deployment — a report
 * would silently return zero rows rather than wrong ones. Portability
 * across environments that have never run `mysql_tzinfo_to_sql` is a
 * deliberate constraint, so the timezone rules come from PHP/IANA
 * instead and reach SQL as plain arithmetic.
 *
 * The observation that makes this cheap: a zone's offset is piecewise
 * constant. Over any reporting period there are only as many distinct
 * offsets as there are DST transitions inside it — normally zero, at
 * most two or three for a full year. So the period is split into
 * segments of constant offset and emitted as a small CASE:
 *
 *     CASE WHEN col < '2027-03-28 01:00:00' THEN col + INTERVAL 0 SECOND
 *          ELSE col + INTERVAL 3600 SECOND END
 *
 * One query, no per-day round trip, no unbounded PHP hydration, and
 * correct to the second on both sides of every transition. Half-hour
 * and 45-minute zones fall out for free because the arithmetic is in
 * seconds, never whole hours.
 */
final class LocalDaySql
{
    /**
     * SQL that shifts a UTC timestamp column into $period's reporting
     * timezone, honouring every offset change inside the period.
     *
     * Returns the expression and its bindings; wrap it in `DATE(...)`
     * for a calendar day or `DAYNAME(...)` for a weekday.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function shiftedColumn(string $column, ReportingPeriod $period): array
    {
        $segments = self::offsetSegments($period);

        // Fixed-offset zone, or a period containing no transition: one
        // constant shift, no CASE at all.
        if (count($segments) === 1) {
            return ['('.$column.' + INTERVAL '.$segments[0]['offset'].' SECOND)', []];
        }

        $sql = 'CASE';
        $bindings = [];

        foreach ($segments as $index => $segment) {
            if ($index === count($segments) - 1) {
                $sql .= ' ELSE '.$column.' + INTERVAL '.$segment['offset'].' SECOND';

                continue;
            }

            $sql .= ' WHEN '.$column.' < ? THEN '.$column.' + INTERVAL '.$segment['offset'].' SECOND';
            $bindings[] = $segment['endsAtUtc'];
        }

        return [$sql.' END', $bindings];
    }

    /** `DATE(...)` over the shifted column — the local calendar day. */
    public static function dateExpression(string $column, ReportingPeriod $period): array
    {
        [$sql, $bindings] = self::shiftedColumn($column, $period);

        return ['DATE('.$sql.')', $bindings];
    }

    /** `DAYNAME(...)` over the shifted column — the local weekday. */
    public static function dayNameExpression(string $column, ReportingPeriod $period): array
    {
        [$sql, $bindings] = self::shiftedColumn($column, $period);

        return ['DAYNAME('.$sql.')', $bindings];
    }

    /**
     * The period split into runs of constant UTC offset.
     *
     * `$boundary` is the UTC instant at which the NEXT segment starts,
     * formatted the way the column stores it, so the comparison is a
     * plain string/datetime comparison MySQL can use an index for.
     *
     * @return list<array{offset: int, endsAtUtc: string}>
     */
    private static function offsetSegments(ReportingPeriod $period): array
    {
        $zone = new DateTimeZone($period->timezone);
        $startUtc = $period->startUtc;
        $endUtc = $period->endUtcExclusive;

        $segments = [];
        $currentOffset = $zone->getOffset($startUtc->toDateTime());

        // getTransitions() with an explicit range returns the transition
        // in force at $start first, then each one inside the window.
        $transitions = $zone->getTransitions($startUtc->getTimestamp(), $endUtc->getTimestamp());

        foreach ($transitions as $transition) {
            if ($transition['ts'] <= $startUtc->getTimestamp() || $transition['ts'] >= $endUtc->getTimestamp()) {
                continue;
            }

            $segments[] = [
                'offset' => $currentOffset,
                'endsAtUtc' => gmdate('Y-m-d H:i:s', $transition['ts']),
            ];

            $currentOffset = $transition['offset'];
        }

        // The final (or only) segment runs to the end of the period.
        $segments[] = ['offset' => $currentOffset, 'endsAtUtc' => $endUtc->format('Y-m-d H:i:s')];

        return $segments;
    }
}
