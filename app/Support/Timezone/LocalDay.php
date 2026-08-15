<?php

declare(strict_types=1);

namespace App\Support\Timezone;

use Carbon\CarbonImmutable;

/**
 * TZ-2A: one calendar day in one specific timezone, together with the
 * absolute UTC interval it occupies.
 *
 * This exists because "which day is this instant on?" and "which
 * instants belong to this day?" are the two questions the availability
 * engine kept answering with `$utcInstant->toDateString()` — which
 * silently means "the day in UTC", not the day of whoever owns the
 * rule. For an instructor in Australia/Sydney an 08:00 local slot
 * carries the PREVIOUS UTC date; for one in America/Los_Angeles an
 * evening slot carries the NEXT one. Both directions produced wrong
 * holiday exclusions and wrong daily-cap buckets (TZ-AUD-005/006).
 *
 * The UTC interval is HALF-OPEN — `[startUtc, endUtcExclusive)`. That is
 * what makes it DST-safe: both boundaries are built as local midnight
 * and only then converted, so a 23-hour spring-forward day and a
 * 25-hour fall-back day both come out exactly right. Adding 86400
 * seconds to `startUtc`, or ending the day at a literal `23:59:59`,
 * is wrong on precisely those two days a year and is why this class
 * takes the boundaries out of caller hands entirely.
 *
 * Deliberately tiny and deliberately NOT a timezone "domain": it holds
 * a date string, a timezone and two instants, and it is used by exactly
 * the availability calculations that need a local calendar day.
 */
final readonly class LocalDay
{
    private function __construct(
        /** The local calendar date, `Y-m-d`, as seen in $timezone. */
        public string $date,
        public string $timezone,
        /** First instant of the local day, in UTC. Inclusive. */
        public CarbonImmutable $startUtc,
        /** First instant of the NEXT local day, in UTC. Exclusive. */
        public CarbonImmutable $endUtcExclusive,
    ) {}

    /** The local day that CONTAINS the given instant, as seen in $timezone. */
    public static function containing(CarbonImmutable $instant, string $timezone): self
    {
        return self::fromLocalStart($instant->setTimezone($timezone)->startOfDay());
    }

    /** The local day with the given `Y-m-d` date in $timezone. */
    public static function of(string $date, string $timezone): self
    {
        return self::fromLocalStart(CarbonImmutable::parse($date, $timezone)->startOfDay());
    }

    /** Does this local day contain the given instant? */
    public function contains(CarbonImmutable $instant): bool
    {
        return $instant->greaterThanOrEqualTo($this->startUtc)
            && $instant->lessThan($this->endUtcExclusive);
    }

    private static function fromLocalStart(CarbonImmutable $localStart): self
    {
        // The next local midnight — NEVER $localStart->addHours(24).
        // On a DST transition day the local day is 23 or 25 hours long,
        // and only calendar-addition-then-startOfDay tracks that.
        $nextLocalStart = $localStart->addDay()->startOfDay();

        return new self(
            date: $localStart->format('Y-m-d'),
            timezone: $localStart->timezoneName,
            startUtc: $localStart->utc(),
            endUtcExclusive: $nextLocalStart->utc(),
        );
    }
}
