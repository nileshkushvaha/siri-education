<?php

declare(strict_types=1);

namespace App\Reporting\Repositories;

use App\Reporting\DTOs\Finance\InstructorFinancialSummaryData;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\ValueObjects\ReportingPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Read-only instructor-compensation aggregates (Phase 18E) — earnings,
 * settlements, withdrawals and payout attempts, each lifecycle stage
 * reported separately (approved ≠ paid ≠ payout success). Amounts are
 * `earning_amount_minor`/`amount_minor` integers grouped by currency —
 * the student-facing booking price is never read here. Requires
 * `ViewInstructorCompensationReports` at the service layer.
 */
final class InstructorFinancialReportRepository
{
    public function summary(ReportingPeriod $period, ReportFilters $filters): InstructorFinancialSummaryData
    {
        $created = DB::table('instructor_earnings')
            ->where('created_at', '>=', $period->startUtc)
            ->where('created_at', '<', $period->endUtcExclusive)
            ->when($filters->instructorId !== null, fn ($q) => $q->where('instructor_id', $filters->instructorId))
            ->when($filters->currencyCode !== null, fn ($q) => $q->where('currency_code', $filters->currencyCode))
            ->when($filters->earningStatus !== null, fn ($q) => $q->where('status', $filters->earningStatus->value));

        $createdByCurrency = (clone $created)
            ->selectRaw('currency_code, count(*) as aggregate, COALESCE(SUM(earning_amount_minor), 0) as amount')
            ->groupBy('currency_code')
            ->get();

        return new InstructorFinancialSummaryData(
            earningsCreatedCount: (int) $createdByCurrency->sum('aggregate'),
            earningsCreatedAmountByCurrency: $createdByCurrency->pluck('amount', 'currency_code')->map(fn ($v) => (int) $v)->all(),
            earningLiabilityByStatusCurrency: $this->earningLiabilityByStatusCurrency($filters),
            unallocatedReleasableByCurrency: DB::table('instructor_earnings')
                ->where('status', 'releasable')
                ->whereNull('settlement_batch_id')
                ->selectRaw('currency_code, COALESCE(SUM(earning_amount_minor), 0) as amount')
                ->groupBy('currency_code')
                ->pluck('amount', 'currency_code')
                ->map(fn ($v) => (int) $v)
                ->all(),
            settlementsByStatus: $this->countsByStatus('instructor_settlement_batches', $period, 'created_at', $filters),
            settlementAmountByCurrency: DB::table('instructor_settlement_batches')
                ->where('status', '!=', 'cancelled')
                ->where('created_at', '>=', $period->startUtc)
                ->where('created_at', '<', $period->endUtcExclusive)
                ->selectRaw('currency_code, COALESCE(SUM(total_amount_minor), 0) as amount')
                ->groupBy('currency_code')
                ->pluck('amount', 'currency_code')
                ->map(fn ($v) => (int) $v)
                ->all(),
            settlementAllocationMismatchCount: $this->settlementAllocationMismatchCount(),
            withdrawalsByStatus: $this->countsByStatus('instructor_withdrawal_requests', $period, 'requested_at', $filters),
            withdrawalRequestedAmountByCurrency: DB::table('instructor_withdrawal_requests')
                ->where('requested_at', '>=', $period->startUtc)
                ->where('requested_at', '<', $period->endUtcExclusive)
                ->selectRaw('currency_code, COALESCE(SUM(amount_minor), 0) as amount')
                ->groupBy('currency_code')
                ->pluck('amount', 'currency_code')
                ->map(fn ($v) => (int) $v)
                ->all(),
            withdrawalPaidAmountByCurrency: DB::table('instructor_withdrawal_requests')
                ->where('status', 'paid')
                ->whereNotNull('paid_at')
                ->where('paid_at', '>=', $period->startUtc)
                ->where('paid_at', '<', $period->endUtcExclusive)
                ->selectRaw('currency_code, COALESCE(SUM(net_amount_minor), 0) as amount')
                ->groupBy('currency_code')
                ->pluck('amount', 'currency_code')
                ->map(fn ($v) => (int) $v)
                ->all(),
            payoutAttemptsByStatus: DB::table('instructor_payout_attempts')
                ->where('created_at', '>=', $period->startUtc)
                ->where('created_at', '<', $period->endUtcExclusive)
                ->selectRaw('status, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status')
                ->map(fn ($v) => (int) $v)
                ->all(),
            openPayoutReconciliationIssues: (int) DB::table('instructor_payout_reconciliation_issues')->where('status', 'open')->count(),
        );
    }

    /**
     * CURRENT-STATE compensation liability: sum of earning amounts by
     * current status per currency (never period-scoped — this answers
     * "what does the platform owe right now, in which lifecycle bucket").
     *
     * @return array<string, array<string, int>> status => currency => minor units
     */
    public function earningLiabilityByStatusCurrency(ReportFilters $filters): array
    {
        $rows = DB::table('instructor_earnings')
            ->when($filters->instructorId !== null, fn ($q) => $q->where('instructor_id', $filters->instructorId))
            ->selectRaw('status, currency_code, COALESCE(SUM(earning_amount_minor), 0) as amount')
            ->groupBy('status', 'currency_code')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[(string) $row->status][(string) $row->currency_code] = (int) $row->amount;
        }

        return $result;
    }

    /** Batches whose `total_amount_minor` differs from the sum of their allocated earnings — an exception signal, never repaired here. */
    public function settlementAllocationMismatchCount(): int
    {
        return (int) DB::table('instructor_settlement_batches')
            ->where('status', '!=', 'cancelled')
            ->whereRaw(<<<'SQL'
                total_amount_minor != COALESCE((
                    SELECT SUM(ie.earning_amount_minor)
                    FROM instructor_earnings ie
                    WHERE ie.settlement_batch_id = instructor_settlement_batches.id
                ), 0)
                SQL)
            ->count();
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /** @return array<string, int> status => count for rows whose $dateColumn falls in the period. */
    private function countsByStatus(string $table, ReportingPeriod $period, string $dateColumn, ReportFilters $filters): array
    {
        return DB::table($table)
            ->where($dateColumn, '>=', $period->startUtc)
            ->where($dateColumn, '<', $period->endUtcExclusive)
            ->when($filters->instructorId !== null, fn ($q) => $q->where('instructor_id', $filters->instructorId))
            ->when($filters->currencyCode !== null, fn ($q) => $q->where('currency_code', $filters->currencyCode))
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}
