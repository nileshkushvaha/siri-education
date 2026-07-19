<?php

declare(strict_types=1);

namespace App\Homework\Services;

use App\Models\User;
use App\Settings\GeneralSettings;
use DateTimeZone;
use Exception;

/**
 * Phase 24K — GAP-020 (Step 13): resolves the timezone a homework
 * due-date reminder is formatted in for its recipient. Mirrors
 * ReportingTimezoneResolver's fallback chain (explicit → platform
 * default → UTC), scoped to the student's own profile timezone —
 * deliberately does not touch GAP-030's booking-notification
 * timezone-per-recipient fix.
 */
final class HomeworkRecipientTimezoneResolver
{
    public const string PLATFORM_FALLBACK = 'UTC';

    public static function resolve(User $student): string
    {
        $profileTimezone = $student->profile?->timezone;

        if (is_string($profileTimezone) && $profileTimezone !== '' && self::isValid($profileTimezone)) {
            return $profileTimezone;
        }

        $configured = app(GeneralSettings::class)->default_timezone;

        if (self::isValid($configured)) {
            return $configured;
        }

        return self::PLATFORM_FALLBACK;
    }

    private static function isValid(string $timezone): bool
    {
        try {
            new DateTimeZone($timezone);

            return true;
        } catch (Exception) {
            return false;
        }
    }
}
