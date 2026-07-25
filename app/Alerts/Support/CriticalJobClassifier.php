<?php

declare(strict_types=1);

namespace App\Alerts\Support;

use App\Alerts\Enums\OperationalAlertCategory;

/**
 * Requirement #4 "critical failed jobs" — not every failed job pages
 * anyone (report exports, CMS/sitemap jobs stay Medium-priority
 * dashboard-only concerns per SRS §26.36); only a failed job whose
 * display name identifies it as touching booking/meeting or financial
 * state is alert-worthy. Matches by substring against the job's
 * `displayName` (the same safe, already-available field
 * `FailedJobPayloadSummary` reads) — never unserializes the job.
 */
final class CriticalJobClassifier
{
    /** @var list<string> */
    private const array FINANCE_PATTERNS = ['Wallet', 'Payment', 'Payout', 'Referral', 'PromotionalCredit', 'Invoice'];

    /** @var list<string> */
    private const array BOOKING_MEETING_PATTERNS = ['Booking', 'Meeting', 'Lesson'];

    public static function category(?string $displayName): ?OperationalAlertCategory
    {
        if ($displayName === null || $displayName === '') {
            return null;
        }

        foreach (self::FINANCE_PATTERNS as $pattern) {
            if (str_contains($displayName, $pattern)) {
                return OperationalAlertCategory::Finance;
            }
        }

        foreach (self::BOOKING_MEETING_PATTERNS as $pattern) {
            if (str_contains($displayName, $pattern)) {
                return OperationalAlertCategory::BookingMeeting;
            }
        }

        return null;
    }
}
