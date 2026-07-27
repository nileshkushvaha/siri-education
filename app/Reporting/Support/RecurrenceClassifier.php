<?php

declare(strict_types=1);

namespace App\Reporting\Support;

/**
 * Data-provenance decision, Outcome B (SRS §6.1): classifies a
 * booking's recurrence bucket from the authoritative `recurrence_frequency`
 * column. A booking created before this column existed
 * but that WAS part of a recurring series (identifiable only via the
 * pre-existing `meta->recurring_group` JSON key) is bucketed as
 * `UNKNOWN_HISTORICAL` — never silently folded into `SINGLE`. Only a
 * booking with neither signal is genuinely `SINGLE`.
 */
final class RecurrenceClassifier
{
    public const string SINGLE = 'single';

    public const string DAILY = 'daily';

    public const string WEEKLY = 'weekly';

    public const string UNKNOWN_HISTORICAL = 'unknown_historical';

    /** @return list<string> every bucket key, in display order. */
    public static function buckets(): array
    {
        return [self::SINGLE, self::DAILY, self::WEEKLY, self::UNKNOWN_HISTORICAL];
    }

    public function label(string $bucket): string
    {
        return match ($bucket) {
            self::SINGLE => 'Single',
            self::DAILY => 'Daily recurring',
            self::WEEKLY => 'Weekly recurring',
            self::UNKNOWN_HISTORICAL => 'Unknown (historical)',
            default => 'Unknown',
        };
    }

    /** The raw SQL CASE expression classifying `bookings.recurrence_frequency`/`bookings.meta` into a bucket key. Never used for the input filter — only for the breakdown/count query. */
    public static function caseExpression(): string
    {
        return <<<'SQL'
            CASE
                WHEN recurrence_frequency = 'daily' THEN 'daily'
                WHEN recurrence_frequency = 'weekly' THEN 'weekly'
                WHEN recurrence_frequency IS NULL AND JSON_EXTRACT(meta, '$.recurring_group') IS NOT NULL THEN 'unknown_historical'
                ELSE 'single'
            END
            SQL;
    }
}
