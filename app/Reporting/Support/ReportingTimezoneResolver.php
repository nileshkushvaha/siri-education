<?php

declare(strict_types=1);

namespace App\Reporting\Support;

use App\Reporting\Exceptions\InvalidReportingTimezoneException;
use App\Support\Timezone\IanaTimezone;
use App\Support\UserTimezoneResolver;

/**
 * The single authoritative reporting-timezone resolver (SRS §7).
 * Resolution order: an explicit report-filter timezone, then the
 * configured platform default (`GeneralSettings::$default_timezone`),
 * then the absolute platform fallback (`UTC`). An explicitly-supplied
 * invalid timezone is always rejected outright — it is never silently
 * replaced by the default, unlike the fallthrough between the other
 * two tiers, which is expected resolution behavior, not a "silent
 * replacement" of a bad explicit value.
 *
 * TZ-1: deliberately NOT merged into UserTimezoneResolver — it answers
 * a different question ("which timezone does this REPORT run in?",
 * which has no user at all) and its reject-on-invalid-explicit contract
 * is the opposite of the resolver's degrade-safely contract. What it no
 * longer owns is the duplicated validation and platform-default
 * lookup: both now come from IanaTimezone / UserTimezoneResolver, so
 * "valid identifier" and "platform default" each have exactly one
 * definition. A caller wanting a specific USER's timezone resolves it
 * with UserTimezoneResolver first and passes the (always-valid) result
 * in here as the explicit value.
 */
final class ReportingTimezoneResolver
{
    public const string PLATFORM_FALLBACK = IanaTimezone::FALLBACK;

    public static function resolve(?string $explicit = null): string
    {
        if ($explicit !== null) {
            if (! self::isValid($explicit)) {
                throw InvalidReportingTimezoneException::forValue($explicit);
            }

            return $explicit;
        }

        return UserTimezoneResolver::platformDefault();
    }

    public static function isValid(string $timezone): bool
    {
        return IanaTimezone::isValid($timezone);
    }
}
