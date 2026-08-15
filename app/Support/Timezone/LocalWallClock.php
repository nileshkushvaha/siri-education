<?php

declare(strict_types=1);

namespace App\Support\Timezone;

use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * TZ-6 (TZ-AUD-022): is a wall-clock reading actually a real, single
 * moment in this timezone?
 *
 * Twice a year in most DST zones the answer is no, and PHP answers
 * anyway — silently:
 *
 *   NONEXISTENT (spring forward). Europe/London jumps 01:00 -> 02:00, so
 *   01:30 never happens. `CarbonImmutable::parse('01:30', 'Europe/London')`
 *   returns 02:30 with no error. A lesson booked "at 01:30" quietly
 *   becomes a lesson at 02:30.
 *
 *   AMBIGUOUS (fall back). America/New_York repeats 01:00-02:00, so
 *   01:30 happens twice, an hour apart. PHP picks the first, arbitrarily.
 *   Student and instructor can each reasonably believe they agreed to
 *   the other one.
 *
 * Neither is a decision a scheduling platform should make on a user's
 * behalf without saying so, which is why this class reports the
 * condition instead of resolving it. Callers refuse; they do not guess.
 *
 * Detection uses IANA transition data via DateTimeZone::getTransitions()
 * — never hardcoded US/EU rules, which would be wrong for Lord Howe's
 * 30-minute shift, wrong for the southern hemisphere, and stale the next
 * time a country changes its mind.
 *
 * Scope: this guards the WALL-CLOCK -> INSTANT boundary only. An
 * absolute instant already selected from a generated slot is
 * unambiguous by construction and must never be rejected just because
 * its local label happens to repeat.
 */
final class LocalWallClock
{
    public const string VALID = 'valid';

    /** The wall-clock reading is skipped by a spring-forward transition. */
    public const string NONEXISTENT = 'nonexistent';

    /** The wall-clock reading occurs twice because of a fall-back transition. */
    public const string AMBIGUOUS = 'ambiguous';

    /**
     * Classify `Y-m-d H:i(:s)` as read in $timezone.
     *
     * Nonexistent is detected by round-tripping: PHP shifts a skipped
     * reading forward, so if formatting the parsed instant back does not
     * reproduce the requested digits, the requested digits never existed.
     *
     * Ambiguity is detected from the offsets either side of the instant:
     * when a fall-back transition moves the clock back by N seconds, every
     * reading in the N seconds before it repeats.
     */
    public static function classify(string $localDateTime, string $timezone): string
    {
        $requested = self::normalize($localDateTime);
        $parsed = CarbonImmutable::parse($localDateTime, $timezone);

        if ($parsed->format('Y-m-d H:i:s') !== $requested) {
            return self::NONEXISTENT;
        }

        return self::isAmbiguous($parsed, $timezone) ? self::AMBIGUOUS : self::VALID;
    }

    public static function isValid(string $localDateTime, string $timezone): bool
    {
        return self::classify($localDateTime, $timezone) === self::VALID;
    }

    /**
     * Classify an INSTANT by the wall-clock reading it presents in
     * $timezone — the form the availability engine needs, where a
     * candidate has already been materialized.
     */
    public static function classifyInstant(CarbonImmutable $instant, string $timezone): string
    {
        return self::classify($instant->setTimezone($timezone)->format('Y-m-d H:i:s'), $timezone);
    }

    /** A human explanation, deliberately free of implementation vocabulary. */
    public static function reason(string $classification, string $timezone): string
    {
        return match ($classification) {
            self::NONEXISTENT => sprintf(
                'That time does not exist on this date in %s — the clocks move forward and skip it. Please choose another time.',
                $timezone,
            ),
            self::AMBIGUOUS => sprintf(
                'That time happens twice on this date in %s, because the clocks move back. Please choose another time so everyone knows which one is meant.',
                $timezone,
            ),
            default => '',
        };
    }

    /**
     * Does the wall clock move BACKWARD within the hour(s) following this
     * instant, such that this reading is repeated?
     */
    private static function isAmbiguous(CarbonImmutable $instant, string $timezone): bool
    {
        $zone = new DateTimeZone($timezone);
        $timestamp = $instant->getTimestamp();

        // A fall-back can repeat readings from at most ~24h earlier
        // (Lord Howe shifts 30 minutes; no zone shifts more than a day).
        foreach ($zone->getTransitions($timestamp, $timestamp + 86400) as $transition) {
            if ($transition['ts'] <= $timestamp) {
                continue;
            }

            $offsetBefore = $zone->getOffset((new CarbonImmutable('@'.($transition['ts'] - 1)))->toDateTime());
            $shift = $offsetBefore - $transition['offset'];

            // Clock moved back by $shift seconds at this transition; every
            // reading in the $shift seconds before it occurs twice.
            if ($shift > 0 && $timestamp >= $transition['ts'] - $shift) {
                return true;
            }
        }

        return false;
    }

    /** `Y-m-d H:i` and `Y-m-d H:i:s` both normalize to seconds precision for comparison. */
    private static function normalize(string $localDateTime): string
    {
        $trimmed = trim(str_replace('T', ' ', $localDateTime));

        return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $trimmed) === 1
            ? $trimmed.':00'
            : $trimmed;
    }
}
