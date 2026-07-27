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

    /**
     * Spatie Media Library's own queued
     * conversion/responsive-image jobs (image optimization/thumbnail
     * generation, SRS §26.24 "File and Media Job Monitoring"). Routed
     * to the existing generic queue-health bucket, not a new category:
     * a failed avatar/cover conversion is a system/queue-health signal,
     * not a booking/finance-continuity one.
     */
    private const array MEDIA_PATTERNS = ['PerformConversionsJob', 'GenerateResponsiveImagesJob'];

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

        foreach (self::MEDIA_PATTERNS as $pattern) {
            if (str_contains($displayName, $pattern)) {
                return OperationalAlertCategory::NotificationQueueSystem;
            }
        }

        return null;
    }
}
