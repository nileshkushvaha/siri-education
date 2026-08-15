<?php

declare(strict_types=1);

namespace App\Support\Timezone;

use DateTimeZone;

/**
 * TZ-1 (TZ-AUD-021 / TZ-AUD-032): the single definition of "is this an
 * acceptable timezone identifier for this platform", shared by the
 * canonical resolver, profile validation, the Booking Wizard's browser
 * detection and the Student Booking API. Before this, three call sites
 * each wrote their own `in_array($tz, timezone_identifiers_list())`.
 *
 * "Acceptable" means a CANONICAL IANA identifier — the default
 * `DateTimeZone::ALL` group (419 zones: `Asia/Kolkata`,
 * `Europe/London`, `America/New_York`, `UTC`, …).
 *
 * That group deliberately EXCLUDES the values that cannot model DST
 * over the lifetime of a stored profile:
 *
 *   'EST', 'CST6CDT', 'GMT'  — fixed-offset / legacy abbreviations
 *   '+05:30', 'GMT+5'        — raw offsets, no DST rules at all
 *   'US/Eastern'             — deprecated backward-compatibility alias
 *   'Asia/Calcutta'          — superseded alias for Asia/Kolkata
 *
 * Every one of those is still accepted by `new DateTimeZone(...)`,
 * which is exactly why constructor success is NOT a sufficient test and
 * `isValid()` exists. Note that this is the same group Laravel's own
 * `timezone` / `timezone:all` rule checks against, so request
 * validation and runtime resolution agree by construction rather than
 * by coincidence.
 *
 * Deliberately NOT a hand-maintained list — PHP's bundled tzdata is the
 * source, so the set tracks upstream zone changes with no code edit.
 */
final class IanaTimezone
{
    /** The absolute last-resort timezone, used only when every configured tier is missing or invalid. */
    public const string FALLBACK = 'UTC';

    /** Is this a canonical IANA identifier? Non-strings, empty strings, offsets and legacy aliases are all false. */
    public static function isValid(mixed $timezone): bool
    {
        return is_string($timezone)
            && $timezone !== ''
            && in_array($timezone, timezone_identifiers_list(), true);
    }

    /**
     * The value when it is canonical, otherwise null — the shape every
     * tier of a fallback chain wants, so a chain reads as a plain
     * `?? ?? ??` rather than a ladder of if-statements.
     */
    public static function sanitize(mixed $timezone): ?string
    {
        return self::isValid($timezone) ? $timezone : null;
    }

    /**
     * Every canonical identifier, for select-menu options and
     * validation rules. Callers must not filter this down to a
     * curated subset — a shortened list is how `Asia/Kolkata` becomes
     * a de facto platform assumption again.
     *
     * @return list<string>
     */
    public static function identifiers(): array
    {
        return timezone_identifiers_list();
    }

    /**
     * A DateTimeZone for an already-validated identifier. Throws for
     * anything else, by design: this is only ever reached through
     * UserTimezoneResolver, which cannot emit an invalid value.
     */
    public static function zone(string $timezone): DateTimeZone
    {
        return new DateTimeZone($timezone);
    }
}
