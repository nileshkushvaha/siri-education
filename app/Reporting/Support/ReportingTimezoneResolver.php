<?php

declare(strict_types=1);

namespace App\Reporting\Support;

use App\Reporting\Exceptions\InvalidReportingTimezoneException;
use App\Settings\GeneralSettings;
use DateTimeZone;
use Exception;

/**
 * The single authoritative reporting-timezone resolver (Phase 18B §7).
 * Resolution order: an explicit report-filter timezone, then the
 * configured platform default (`GeneralSettings::$default_timezone`),
 * then the absolute platform fallback (`UTC`). An explicitly-supplied
 * invalid timezone is always rejected outright — it is never silently
 * replaced by the default, unlike the fallthrough between the other
 * two tiers, which is expected resolution behavior, not a "silent
 * replacement" of a bad explicit value.
 */
final class ReportingTimezoneResolver
{
    public const string PLATFORM_FALLBACK = 'UTC';

    public static function resolve(?string $explicit = null): string
    {
        if ($explicit !== null) {
            if (! self::isValid($explicit)) {
                throw InvalidReportingTimezoneException::forValue($explicit);
            }

            return $explicit;
        }

        $configured = app(GeneralSettings::class)->default_timezone;

        if (self::isValid($configured)) {
            return $configured;
        }

        return self::PLATFORM_FALLBACK;
    }

    public static function isValid(string $timezone): bool
    {
        try {
            new DateTimeZone($timezone);

            return true;
        } catch (Exception) {
            return false;
        }
    }
}
