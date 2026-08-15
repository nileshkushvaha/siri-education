<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use App\Settings\GeneralSettings;
use App\Support\Timezone\IanaTimezone;
use DateTimeZone;
use Throwable;

/**
 * TZ-1: THE single authoritative answer to "what timezone should this
 * user use?". Promoted from the notification-only
 * RecipientTimezoneResolver, whose logic was always generic — only its
 * name, its namespace and its missing Country tier were
 * recipient-specific.
 *
 * Resolution order (SRS-21-6, SRS §21.13/§21.16, TZ-AUD-001):
 *
 *   1. the user's OWN explicit, valid profile timezone
 *   2. their Country's configured default timezone
 *   3. the platform default (GeneralSettings::$default_timezone)
 *   4. UTC
 *
 * Each tier is validated INDEPENDENTLY and falls through on failure, so
 * a legacy or malformed stored value degrades to the next tier instead
 * of throwing (TZ-AUD-023). A bad `profile.timezone` must never take
 * out a dashboard render, an availability page or a queued notification
 * job — the previous inline
 * `$user->profile?->timezone ?: config('app.timezone')` pattern passed
 * whatever was stored straight into `->timezone()`, which throws.
 *
 * Tier 2 is a FALLBACK DEFAULT, never geolocation. The United States,
 * Canada and Australia each span several zones, so a Country default is
 * only ever "where we start someone who has not told us" — an explicit
 * profile timezone always wins, and a user who moves their Country
 * keeps the timezone they chose (see UpdateProfileAction).
 *
 * Never resolves the Country from a phone prefix, an IP address or a
 * browser API: the persisted `user_profiles.country_id` relation is the
 * only Country source, and `users` carries no competing column.
 *
 * Deliberately takes an explicit User and never calls auth(): a
 * notification recipient is not the authenticated user, a queued job
 * has no session at all, an admin surface chooses whose timezone it
 * wants, and a test needs determinism. The CALLER owns which user is
 * the viewer or recipient — which is also why there is no
 * `currentUserTimezone()` convenience method here.
 *
 * Static, matching the call convention its consumers already use;
 * stateless and free of per-request caching, so it holds no user
 * context between calls.
 */
final class UserTimezoneResolver
{
    /** Tier 4 — reached only when profile, Country and platform settings are all missing or invalid. */
    public const string PLATFORM_FALLBACK = IanaTimezone::FALLBACK;

    /** The user's resolved canonical IANA identifier. Guaranteed valid — an invalid value can never leave this method. */
    public static function resolve(User $user): string
    {
        $profile = $user->profile;

        return IanaTimezone::sanitize($profile?->timezone)
            ?? IanaTimezone::sanitize($profile?->country?->default_timezone)
            ?? self::platformDefault();
    }

    /** The same resolution as a DateTimeZone, for callers doing date arithmetic rather than storing/labelling a string. */
    public static function resolveZone(User $user): DateTimeZone
    {
        return IanaTimezone::zone(self::resolve($user));
    }

    /**
     * Tiers 3–4 on their own, for the handful of surfaces that need a
     * timezone with no user in hand (an anonymous booking-wizard
     * visitor, a system-generated record). Kept here so "the platform
     * default, validated" has one definition too.
     *
     * A missing or corrupt settings record degrades to UTC rather than
     * propagating a container/settings exception — this method is
     * reached from queued jobs and error pages, where throwing is the
     * worst possible outcome.
     */
    public static function platformDefault(): string
    {
        try {
            $configured = app(GeneralSettings::class)->default_timezone;
        } catch (Throwable) {
            return self::PLATFORM_FALLBACK;
        }

        return IanaTimezone::sanitize($configured) ?? self::PLATFORM_FALLBACK;
    }
}
