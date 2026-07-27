<?php

declare(strict_types=1);

namespace App\Reporting\Repositories;

use App\Models\WalletRecharge;
use App\Reporting\DTOs\Finance\RefundLinkageRow;
use App\Reporting\DTOs\Finance\RefundSummaryData;
use App\Reporting\DTOs\Finance\WalletFinancialSummaryData;
use App\Reporting\DTOs\Finance\WalletRechargeMonitoringRow;
use App\Reporting\DTOs\Finance\WalletRechargeMonitoringSummary;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\ValueObjects\ReportingPeriod;
use App\Settings\WalletSettings;
use App\Wallet\Enums\WalletRechargeOperationalClassification;
use App\Wallet\Enums\WalletRechargeStatus;
use App\Wallet\Services\WalletRechargeReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\DB;

/**
 * Read-only wallet + refund aggregates. All amounts are
 * integer minor units summed in SQL and always grouped by currency —
 * no float ever touches a monetary value, and no cross-currency total
 * exists anywhere. Never invokes the ledger writer or any mutation
 * path.
 */
final class WalletFinancialReportRepository
{
    public function summary(ReportingPeriod $period, ReportFilters $filters): WalletFinancialSummaryData
    {
        $settings = app(WalletSettings::class);

        return new WalletFinancialSummaryData(
            movements: $this->movements($period, $filters),
            currentLiabilityByCurrency: $this->currentLiabilityByCurrency(),
            liabilityAsOf: CarbonImmutable::now()->toIso8601String(),
            walletCount: (int) DB::table('wallets')->count(),
            positiveBalanceWallets: (int) DB::table('wallets')->where('balance_minor', '>', 0)->count(),
            zeroBalanceWallets: (int) DB::table('wallets')->where('balance_minor', 0)->count(),
            lowBalanceWallets: $this->lowBalanceWallets($settings),
            lowBalanceThreshold: $settings->low_balance_threshold,
            reversalCount: (int) DB::table('wallet_ledger_entries')
                ->where('status', 'reversed')
                ->where('created_at', '>=', $period->startUtc)
                ->where('created_at', '<', $period->endUtcExclusive)
                ->count(),
            balanceMismatchCount: $this->balanceMismatchCount(),
        );
    }

    /**
     * Period-event ledger movements grouped by (currency, type,
     * direction). Includes Posted AND Reversed rows: a reversal writes
     * an explicit offsetting Posted row while the original flips to
     * Reversed — including both keeps gross activity and its reversal
     * each visible (documented in the DTO).
     *
     * @return list<array{currency: string, entryType: string, direction: string, count: int, amountMinor: int}>
     */
    public function movements(ReportingPeriod $period, ReportFilters $filters): array
    {
        $query = DB::table('wallet_ledger_entries')
            ->whereIn('status', ['posted', 'reversed'])
            ->where('created_at', '>=', $period->startUtc)
            ->where('created_at', '<', $period->endUtcExclusive);

        if ($filters->currencyCode !== null) {
            $query->where('currency_code', $filters->currencyCode);
        }

        if ($filters->walletTransactionType !== null) {
            $query->where('entry_type', $filters->walletTransactionType->value);
        }

        if ($filters->walletTransactionStatus !== null) {
            $query->where('status', $filters->walletTransactionStatus->value);
        }

        if ($filters->studentId !== null) {
            $query->where('user_id', $filters->studentId);
        }

        return $query
            ->selectRaw('currency_code, entry_type, direction, count(*) as aggregate, COALESCE(SUM(amount_minor), 0) as amount')
            ->groupBy('currency_code', 'entry_type', 'direction')
            ->orderBy('currency_code')
            ->orderBy('entry_type')
            ->get()
            ->map(fn ($row) => [
                'currency' => (string) $row->currency_code,
                'entryType' => (string) $row->entry_type,
                'direction' => (string) $row->direction,
                'count' => (int) $row->aggregate,
                'amountMinor' => (int) $row->amount,
            ])
            ->all();
    }

    /** AS-OF-NOW liability: sum of guarded stored balances per currency — never period-scoped. @return array<string, int> */
    public function currentLiabilityByCurrency(): array
    {
        return DB::table('wallets')
            ->selectRaw('currency_code, COALESCE(SUM(balance_minor), 0) as liability')
            ->groupBy('currency_code')
            ->pluck('liability', 'currency_code')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Stored balance vs the wallet's latest ledger `balance_after_minor`
     * — a read-only signal; nothing is ever repaired from a report.
     */
    public function balanceMismatchCount(): int
    {
        return (int) DB::table('wallets')
            ->whereRaw(<<<'SQL'
                COALESCE((
                    SELECT wle.balance_after_minor
                    FROM wallet_ledger_entries wle
                    WHERE wle.wallet_id = wallets.id AND wle.status IN ('posted', 'reversed')
                    ORDER BY wle.created_at DESC, wle.id DESC
                    LIMIT 1
                ), 0) != wallets.balance_minor
                SQL)
            ->count();
    }

    // ── Recharge attempts (SRS §13.33 operational visibility) ──────────────

    /**
     * Attempt-level breakdown — the ledger movements above only ever
     * show a SETTLED (RechargeConfirmed) credit; a pending/failed/
     * credit_failed attempt never posts a ledger entry at all, so this
     * is the only place those states are visible.
     *
     * @return array<string, int>
     */
    public function rechargeAttemptStatusBreakdown(): array
    {
        return DB::table('wallet_recharges')
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Recharge attempts a provider has captured but that have not yet
     * reached the wallet — SRS §13.33's "payment succeeded but wallet
     * credit failed" / "callback delayed" cases. Read-only; never
     * mutates anything — WalletRechargeReconciliationService owns the
     * actual retry.
     *
     * @return list<array{id: string, provider: string, amountMinor: int, currency: string, status: string, failureCode: ?string, providerConfirmedAt: ?string, lastSyncedAt: ?string}>
     */
    public function uncreditedCapturedRecharges(int $limit = 50): array
    {
        return DB::table('wallet_recharges')
            ->whereIn('status', ['credit_pending', 'credit_failed'])
            ->orderBy('provider_confirmed_at')
            ->limit($limit)
            ->get(['id', 'provider', 'amount_minor', 'currency_code', 'status', 'failure_code', 'provider_confirmed_at', 'last_synced_at'])
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'provider' => (string) $row->provider,
                'amountMinor' => (int) $row->amount_minor,
                'currency' => (string) $row->currency_code,
                'status' => (string) $row->status,
                'failureCode' => $row->failure_code !== null ? (string) $row->failure_code : null,
                'providerConfirmedAt' => $row->provider_confirmed_at !== null ? (string) $row->provider_confirmed_at : null,
                'lastSyncedAt' => $row->last_synced_at !== null ? (string) $row->last_synced_at : null,
            ])
            ->all();
    }

    /**
     * Current-state (never period-scoped) counts for the monitoring
     * summary cards — an attempt stuck for a week is exactly as
     * operationally relevant today as one stuck five minutes ago.
     */
    public function rechargeMonitoringSummary(): WalletRechargeMonitoringSummary
    {
        $breakdown = $this->rechargeAttemptStatusBreakdown();
        $cutoff = CarbonImmutable::now()->subMinutes(WalletRechargeReconciliationService::DUE_AFTER_MINUTES);

        $stale = (int) DB::table('wallet_recharges')
            ->whereIn('status', [WalletRechargeStatus::ProviderCreated->value, WalletRechargeStatus::AwaitingConfirmation->value])
            ->where('created_at', '<=', $cutoff)
            ->count();

        return new WalletRechargeMonitoringSummary(
            providerCreated: $breakdown[WalletRechargeStatus::ProviderCreated->value] ?? 0,
            awaitingConfirmation: $breakdown[WalletRechargeStatus::AwaitingConfirmation->value] ?? 0,
            capturedCreditPending: $breakdown[WalletRechargeStatus::CreditPending->value] ?? 0,
            capturedCreditFailed: $breakdown[WalletRechargeStatus::CreditFailed->value] ?? 0,
            succeeded: $breakdown[WalletRechargeStatus::Succeeded->value] ?? 0,
            providerTerminalFailures: $breakdown[WalletRechargeStatus::Failed->value] ?? 0,
            stale: $stale,
            generatedAtIso: CarbonImmutable::now()->toIso8601String(),
        );
    }

    /**
     * The operational monitoring table — one row per recharge attempt,
     * safe/masked fields only. Read-only: never mutates a recharge;
     * WalletRechargeReconciliationService/WalletRechargeService own
     * every state transition.
     *
     * @param  array{status?: ?string, provider?: ?string, currencyCode?: ?string, reference?: ?string, capturedUncreditedOnly?: bool, staleOnly?: bool, periodStartUtc?: ?CarbonImmutable, periodEndUtcExclusive?: ?CarbonImmutable}  $params
     */
    public function paginatedRechargeMonitoring(array $params, bool $maskStudentIdentity, int $perPage = 25): LengthAwarePaginator
    {
        $cutoff = CarbonImmutable::now()->subMinutes(WalletRechargeReconciliationService::DUE_AFTER_MINUTES);

        $query = WalletRecharge::query()->with(['user:id,name,first_name']);

        if (filled($params['status'] ?? null)) {
            $query->where('status', $params['status']);
        }

        if (filled($params['provider'] ?? null)) {
            $query->where('provider', $params['provider']);
        }

        if (filled($params['currencyCode'] ?? null)) {
            $query->where('currency_code', strtoupper((string) $params['currencyCode']));
        }

        if (filled($params['reference'] ?? null)) {
            // idempotency_key is varchar(100) — the column itself bounds
            // the search input; no unbounded LIKE scan risk beyond that.
            $query->where('idempotency_key', 'like', '%'.mb_substr((string) $params['reference'], 0, 100).'%');
        }

        if (($params['capturedUncreditedOnly'] ?? false) === true) {
            $query->whereIn('status', [WalletRechargeStatus::CreditPending->value, WalletRechargeStatus::CreditFailed->value]);
        }

        if (($params['staleOnly'] ?? false) === true) {
            $query->whereIn('status', [WalletRechargeStatus::ProviderCreated->value, WalletRechargeStatus::AwaitingConfirmation->value])
                ->where('created_at', '<=', $cutoff);
        }

        if (($params['periodStartUtc'] ?? null) instanceof CarbonImmutable) {
            $query->where('created_at', '>=', $params['periodStartUtc']);
        }

        if (($params['periodEndUtcExclusive'] ?? null) instanceof CarbonImmutable) {
            $query->where('created_at', '<', $params['periodEndUtcExclusive']);
        }

        return $query
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->through(fn (WalletRecharge $recharge): WalletRechargeMonitoringRow => new WalletRechargeMonitoringRow(
                id: $recharge->id,
                reference: $recharge->idempotency_key,
                studentLabel: $this->studentLabel($recharge, $maskStudentIdentity),
                currencyCode: $recharge->currency_code,
                amountMinor: $recharge->amount_minor,
                provider: $recharge->provider,
                status: $recharge->status,
                classification: WalletRechargeOperationalClassification::fromRecharge($recharge, $cutoff),
                failureCode: $recharge->failure_code,
                providerConfirmedAtUtc: $recharge->provider_confirmed_at,
                succeededAtUtc: $recharge->succeeded_at,
                failedAtUtc: $recharge->failed_at,
                lastSyncedAtUtc: $recharge->last_synced_at,
                createdAtUtc: CarbonImmutable::parse($recharge->created_at, 'UTC'),
                maskedProviderOrderId: $this->mask($recharge->provider_order_id),
                maskedProviderPaymentId: $this->mask($recharge->provider_payment_id),
            ));
    }

    /** Mirrors StudentEngagementReportService::studentLabel()'s exact masking convention — first-initial + '***' when the viewer lacks ViewStudentReports. */
    private function studentLabel(WalletRecharge $recharge, bool $mask): string
    {
        if (! $mask) {
            return $recharge->user?->name ?? sprintf('Student #%d', $recharge->user_id);
        }

        $first = trim((string) $recharge->user?->first_name);

        return $first === '' ? 'Student' : mb_substr($first, 0, 1).'***';
    }

    // ── Refunds (lesson financial dispositions + executed wallet credits) ──

    public function refundSummary(ReportingPeriod $period, ReportFilters $filters): RefundSummaryData
    {
        $decisions = DB::table('lesson_financial_dispositions')
            ->where('student_disposition', 'full_wallet_refund_required')
            ->where('evaluated_at', '>=', $period->startUtc)
            ->where('evaluated_at', '<', $period->endUtcExclusive);

        $byStatus = (clone $decisions)
            ->selectRaw('processing_status, count(*) as aggregate')
            ->groupBy('processing_status')
            ->pluck('aggregate', 'processing_status')
            ->map(fn ($v) => (int) $v)
            ->all();

        $executed = DB::table('wallet_ledger_entries')
            ->where('entry_type', 'refund')
            ->whereIn('status', ['posted', 'reversed'])
            ->where('created_at', '>=', $period->startUtc)
            ->where('created_at', '<', $period->endUtcExclusive);

        if ($filters->currencyCode !== null) {
            $executed->where('currency_code', $filters->currencyCode);
        }

        $executedByCurrency = (clone $executed)
            ->where('direction', 'credit')
            ->selectRaw('currency_code, count(*) as aggregate, COALESCE(SUM(amount_minor), 0) as amount')
            ->groupBy('currency_code')
            ->get();

        return new RefundSummaryData(
            refundDecisionsInPeriod: (clone $decisions)->count(),
            decisionsByStatus: $byStatus,
            pendingExecution: (int) DB::table('lesson_financial_dispositions')
                ->where('student_disposition', 'full_wallet_refund_required')
                ->where('processing_status', 'ready')
                ->where('admin_hold', false)
                ->whereNull('refund_ledger_entry_id')
                ->count(),
            executedCount: (int) $executedByCurrency->sum('aggregate'),
            executedAmountByCurrency: $executedByCurrency->pluck('amount', 'currency_code')->map(fn ($v) => (int) $v)->all(),
            manualReviewCount: (int) DB::table('lesson_financial_dispositions')
                ->where('processing_status', 'manual_review')
                ->count(),
        );
    }

    /** Bounded, paginated refund linkage table — decisions newest first. */
    public function paginatedRefundLinkage(ReportingPeriod $period, int $perPage = 25): LengthAwarePaginator
    {
        $base = DB::table('lesson_financial_dispositions')
            ->join('bookings', 'bookings.id', '=', 'lesson_financial_dispositions.booking_id')
            ->leftJoin('wallet_ledger_entries', 'wallet_ledger_entries.id', '=', 'lesson_financial_dispositions.refund_ledger_entry_id')
            ->where('lesson_financial_dispositions.student_disposition', 'full_wallet_refund_required')
            ->where('lesson_financial_dispositions.evaluated_at', '>=', $period->startUtc)
            ->where('lesson_financial_dispositions.evaluated_at', '<', $period->endUtcExclusive);

        $total = (clone $base)->count();
        $page = Paginator::resolveCurrentPage();

        $rows = $base
            ->orderByDesc('lesson_financial_dispositions.evaluated_at')
            ->orderBy('lesson_financial_dispositions.id')
            ->forPage($page, $perPage)
            ->get([
                'lesson_financial_dispositions.id as disposition_id',
                'lesson_financial_dispositions.lesson_id',
                'lesson_financial_dispositions.outcome',
                'lesson_financial_dispositions.processing_status',
                'lesson_financial_dispositions.reason_code',
                'lesson_financial_dispositions.admin_hold',
                'lesson_financial_dispositions.evaluated_at',
                'lesson_financial_dispositions.refund_executed_at',
                'lesson_financial_dispositions.payment_reference',
                'bookings.reference as booking_reference',
                'wallet_ledger_entries.amount_minor',
                'wallet_ledger_entries.currency_code',
            ])
            ->map(fn ($row) => new RefundLinkageRow(
                dispositionId: (string) $row->disposition_id,
                bookingReference: (string) $row->booking_reference,
                lessonId: (string) $row->lesson_id,
                lessonOutcomeLabel: ucwords(str_replace('_', ' ', (string) $row->outcome)),
                processingStatusLabel: ucwords(str_replace('_', ' ', (string) $row->processing_status)),
                reasonCode: $row->reason_code !== null ? (string) $row->reason_code : null,
                amountMinor: $row->amount_minor !== null ? (int) $row->amount_minor : null,
                currency: $row->currency_code !== null ? (string) $row->currency_code : null,
                decidedAtUtc: CarbonImmutable::parse((string) $row->evaluated_at, 'UTC'),
                executedAtUtc: $row->refund_executed_at !== null ? CarbonImmutable::parse((string) $row->refund_executed_at, 'UTC') : null,
                adminHold: (bool) $row->admin_hold,
                maskedPaymentReference: $this->mask($row->payment_reference !== null ? (string) $row->payment_reference : null),
            ));

        return new Paginator($rows, $total, $perPage, $page, ['path' => Paginator::resolveCurrentPath()]);
    }

    // ── Promotional credits ─────────────────────────────

    /**
     * Per-campaign issuance totals — a natural extension of this same
     * repository (requirement #9), not a speculative new module.
     * `movements()` above already surfaces promotional_credit rows in
     * its generic entry_type breakdown with zero code change; this adds
     * the one thing that breakdown cannot show: which CAMPAIGN the
     * credits came from.
     *
     * @return list<array{campaignId: int, campaignName: string, issuedCount: int, issuedAmountMinor: int, currencyCode: string, budgetMinor: ?int}>
     */
    public function promotionalCreditCampaignSummary(): array
    {
        return DB::table('promotional_credit_issuances')
            ->join('promotional_credit_campaigns', 'promotional_credit_campaigns.id', '=', 'promotional_credit_issuances.campaign_id')
            ->selectRaw('promotional_credit_campaigns.id as campaign_id, promotional_credit_campaigns.name as campaign_name, promotional_credit_campaigns.total_budget_minor, promotional_credit_issuances.currency_code, count(*) as aggregate, COALESCE(SUM(promotional_credit_issuances.amount_minor), 0) as amount')
            ->groupBy('promotional_credit_campaigns.id', 'promotional_credit_campaigns.name', 'promotional_credit_campaigns.total_budget_minor', 'promotional_credit_issuances.currency_code')
            ->orderBy('promotional_credit_campaigns.name')
            ->get()
            ->map(fn ($row) => [
                'campaignId' => (int) $row->campaign_id,
                'campaignName' => (string) $row->campaign_name,
                'issuedCount' => (int) $row->aggregate,
                'issuedAmountMinor' => (int) $row->amount,
                'currencyCode' => (string) $row->currency_code,
                'budgetMinor' => $row->total_budget_minor !== null ? (int) $row->total_budget_minor : null,
            ])
            ->all();
    }

    /** Manual (non-campaign) issuances are excluded from campaign summaries by definition — this is their own bounded aggregate. */
    public function manualPromotionalCreditTotal(): array
    {
        return DB::table('promotional_credit_issuances')
            ->whereNull('campaign_id')
            ->selectRaw('currency_code, count(*) as aggregate, COALESCE(SUM(amount_minor), 0) as amount')
            ->groupBy('currency_code')
            ->get()
            ->map(fn ($row) => [
                'currencyCode' => (string) $row->currency_code,
                'issuedCount' => (int) $row->aggregate,
                'issuedAmountMinor' => (int) $row->amount,
            ])
            ->all();
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function lowBalanceWallets(WalletSettings $settings): int
    {
        // The configured threshold is a major-unit float; wallets store minor
        // units. Compare per-currency using the currency's own exponent.
        return (int) DB::table('wallets')
            ->join('currencies', 'currencies.id', '=', 'wallets.currency_id')
            ->whereRaw('wallets.balance_minor < ? * POW(10, currencies.minor_units)', [$settings->low_balance_threshold])
            ->where('wallets.balance_minor', '>', 0)
            ->count();
    }

    /** Masked provider/payment reference — first 4 + last 4 only, never the full identifier. */
    private function mask(?string $reference): ?string
    {
        if ($reference === null || $reference === '') {
            return null;
        }

        if (mb_strlen($reference) <= 8) {
            return mb_substr($reference, 0, 2).'…';
        }

        return mb_substr($reference, 0, 4).'…'.mb_substr($reference, -4);
    }
}
