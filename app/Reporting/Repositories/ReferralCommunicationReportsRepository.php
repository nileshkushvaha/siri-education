<?php

declare(strict_types=1);

namespace App\Reporting\Repositories;

use App\Reporting\DTOs\Communication\NotificationActivityData;
use App\Reporting\DTOs\Communication\ReferralActivityData;
use App\Reporting\DTOs\Communication\ReviewQualityRatesData;
use App\Reporting\DTOs\Operations\LabeledCountRow;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\ValueObjects\ReportingPeriod;
use App\Settings\FeatureSettings;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only referral, review-rate and notification aggregates
 * (Phase 18G). Three deliberately small sections against the only
 * authoritative Version 1 sources: the wallet ledger (referral), review
 * eligibilities + the Phase 17 rating aggregate table (review rates),
 * and in-app notifications + the dispatch idempotency log
 * (notifications). Everything is SQL aggregation; no payload, token,
 * reporter identity, moderation note or provider secret column is ever
 * selected. Messaging has no Version 1 domain and no method here.
 */
final class ReferralCommunicationReportsRepository
{
    // ── Referral (wallet ledger is the single source of truth) ────────────

    public function referralActivity(ReportingPeriod $period, ReportFilters $filters): ReferralActivityData
    {
        // Gross executed referral credits: entries written in the period as
        // Posted, including those later flipped to Reversed (the reversal is
        // counted separately). Pending/Failed rows were never credited value.
        $executed = DB::table('wallet_ledger_entries')
            ->where('entry_type', 'referral_credit')
            ->where('direction', 'credit')
            ->whereIn('status', ['posted', 'reversed'])
            ->where('created_at', '>=', $period->startUtc)
            ->where('created_at', '<', $period->endUtcExclusive);

        if ($filters->currencyCode !== null) {
            $executed->where('currency_code', $filters->currencyCode);
        }

        $byCurrency = (clone $executed)
            ->selectRaw('currency_code, count(*) as aggregate, COALESCE(SUM(amount_minor), 0) as amount')
            ->groupBy('currency_code')
            ->get();

        return new ReferralActivityData(
            creditsExecutedInPeriod: (int) $byCurrency->sum('aggregate'),
            creditedAmountByCurrency: $byCurrency->pluck('amount', 'currency_code')->map(fn ($v) => (int) $v)->all(),
            distinctRecipientsInPeriod: (int) (clone $executed)->distinct()->count('user_id'),
            reversalsInPeriod: (int) DB::table('wallet_ledger_entries')
                ->where('entry_type', 'referral_credit')
                ->where('status', 'reversed')
                ->where('created_at', '>=', $period->startUtc)
                ->where('created_at', '<', $period->endUtcExclusive)
                ->count(),
            referralModuleEnabled: app(FeatureSettings::class)->referral_enabled,
        );
    }

    // ── Review submission rates (complementary to the Phase 17 dashboard) ─

    public function reviewQualityRates(ReportingPeriod $period, ReportFilters $filters): ReviewQualityRatesData
    {
        $windows = DB::table('lesson_review_eligibilities')
            ->where('opens_at', '>=', $period->startUtc)
            ->where('opens_at', '<', $period->endUtcExclusive);

        if ($filters->instructorId !== null) {
            $windows->where('instructor_id', $filters->instructorId);
        }

        // One grouped pass: concluded = used OR expired-by-time; revoked and
        // manual-review windows are excluded from every denominator.
        $rows = (clone $windows)
            ->selectRaw("lesson_type,
                SUM(revoked_at is not null) as revoked,
                SUM(status = 'manual_review') as manual_review,
                SUM(revoked_at is null and status != 'manual_review' and (used_at is not null or expires_at <= ?)) as concluded,
                SUM(revoked_at is null and status != 'manual_review' and used_at is not null) as used", [now('UTC')])
            ->groupBy('lesson_type')
            ->get()
            ->keyBy('lesson_type');

        $sum = fn (string $column): int => (int) $rows->sum(fn ($row) => (int) $row->{$column});

        $rate = function (int $used, int $concluded): ?float {
            return $concluded > 0 ? round(($used / $concluded) * 100, 1) : null;
        };

        $demo = $rows->get('demo');
        $paid = $rows->get('paid');

        $aggregate = DB::table('instructor_rating_aggregates')
            ->selectRaw('SUM(overall_rating_sum) as rating_sum, SUM(eligible_review_count) as review_count')
            ->first();
        $reviewCount = (int) ($aggregate->review_count ?? 0);

        return new ReviewQualityRatesData(
            submissionRate: $rate($sum('used'), $sum('concluded')),
            demoSubmissionRate: $rate((int) ($demo->used ?? 0), (int) ($demo->concluded ?? 0)),
            paidSubmissionRate: $rate((int) ($paid->used ?? 0), (int) ($paid->concluded ?? 0)),
            concludedWindowsInPeriod: $sum('concluded'),
            usedWindowsInPeriod: $sum('used'),
            revokedExcludedInPeriod: $sum('revoked'),
            manualReviewExcludedInPeriod: $sum('manual_review'),
            // Identical formula to AdminQualityDashboardRepository::platformAverageRating —
            // the aggregate table (Phase 17) stays the single calculation owner.
            platformAverageRating: $reviewCount > 0 ? round((int) $aggregate->rating_sum / $reviewCount, 2) : null,
            publishedEligibleReviewCount: $reviewCount,
            pendingReviewReports: (int) DB::table('review_reports')->where('status', 'pending')->count(),
            openQualityAlerts: (int) DB::table('quality_alerts')->whereIn('status', ['open', 'under_review'])->count(),
        );
    }

    // ── Notifications (in-app + dispatch idempotency log only) ────────────

    public function notificationActivity(ReportingPeriod $period): NotificationActivityData
    {
        $cohort = DB::table('notifications')
            ->where('created_at', '>=', $period->startUtc)
            ->where('created_at', '<', $period->endUtcExclusive);

        $cohortTotals = (clone $cohort)
            ->selectRaw('count(*) as total, SUM(read_at is not null) as read_count')
            ->first();

        $created = (int) ($cohortTotals->total ?? 0);
        $read = (int) ($cohortTotals->read_count ?? 0);

        return new NotificationActivityData(
            inAppCreatedInPeriod: $created,
            inAppReadOfPeriodCohort: $read,
            readRate: $created > 0 ? round(($read / $created) * 100, 1) : null,
            currentUnread: (int) DB::table('notifications')->whereNull('read_at')->count(),
            byType: $this->classBasenameCounts((clone $cohort), 'type'),
            dedupClaimsInPeriod: (int) DB::table('notification_dispatch_log')
                ->where('created_at', '>=', $period->startUtc)
                ->where('created_at', '<', $period->endUtcExclusive)
                ->count(),
            dedupByClass: $this->classBasenameCounts(
                DB::table('notification_dispatch_log')
                    ->where('created_at', '>=', $period->startUtc)
                    ->where('created_at', '<', $period->endUtcExclusive),
                'notification_class',
            ),
        );
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /**
     * Top-N class counts with the namespace stripped server-side — a class
     * basename is safe metadata; a notification payload never is.
     *
     * @return list<LabeledCountRow>
     */
    private function classBasenameCounts(Builder $query, string $column, int $limit = 15): array
    {
        return $query
            ->selectRaw("{$column} as label, count(*) as aggregate")
            ->groupBy('label')
            ->orderByDesc('aggregate')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => new LabeledCountRow(
                (string) (str_contains((string) $row->label, '\\') ? substr((string) $row->label, strrpos((string) $row->label, '\\') + 1) : $row->label),
                (int) $row->aggregate,
            ))
            ->all();
    }
}
