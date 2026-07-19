<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use App\Settings\GeneralSettings;
use DateTimeZone;
use Exception;

/**
 * Phase 24L — GAP-030 (SRS-21-6, SRS §21.13/§21.16): the single
 * authoritative recipient-timezone resolver for every notification
 * that displays a scheduled date/time. Extracted from the
 * homework-reminder-specific resolver of the same shape (Phase 24K) —
 * the logic was always generic; only the name and namespace were
 * homework-specific.
 *
 * Resolution order: the recipient's OWN valid stored profile timezone
 * → the platform's configured default → UTC. Never the timezone of
 * another user (e.g. never the booking student's timezone used as a
 * fallback for the instructor's copy), never inferred from a request
 * parameter or IP, never the server's OS timezone.
 */
final class RecipientTimezoneResolver
{
    public const string PLATFORM_FALLBACK = 'UTC';

    public static function resolve(User $recipient): string
    {
        $profileTimezone = $recipient->profile?->timezone;

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
