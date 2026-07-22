<?php

declare(strict_types=1);

namespace App\Reporting\Contracts;

use App\Models\User;
use App\Reporting\DTOs\Finance\InstructorFinancialSummaryData;
use App\Reporting\DTOs\Finance\PaymentFinancialSummaryData;
use App\Reporting\DTOs\Finance\ReconciliationIssueRow;
use App\Reporting\DTOs\Finance\RefundLinkageRow;
use App\Reporting\DTOs\Finance\RefundSummaryData;
use App\Reporting\DTOs\Finance\WalletFinancialSummaryData;
use App\Reporting\DTOs\Finance\WalletRechargeMonitoringRow;
use App\Reporting\DTOs\Finance\WalletRechargeMonitoringSummary;
use App\Reporting\DTOs\Operations\OperationsReportFreshnessData;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\ValueObjects\ReportingPeriod;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Phase 18E — the single read-only entry point for financial
 * reporting. Every method independently authorizes against its own
 * report definition and permission:
 *
 * - wallet/refund methods    → `ViewWalletReports`
 * - payment methods          → `ViewPaymentReports`
 * - instructor financials    → `ViewInstructorCompensationReports`
 * - the overview page        → `ViewFinanceReports`, and each section
 *   it embeds re-checks its own stricter permission — general finance
 *   access never implies wallet, payment or compensation detail.
 *
 * Strictly read-only: never initiates, retries, reconciles, refunds,
 * settles or pays out; never mutates a feature switch; never calls a
 * provider; never writes audit entries for ordinary views.
 */
interface FinancialReportsServiceInterface
{
    /** @throws AuthorizationException */
    public function walletSummary(User $user, ReportingPeriod $period, ReportFilters $filters): WalletFinancialSummaryData;

    /** @throws AuthorizationException */
    public function refundSummary(User $user, ReportingPeriod $period, ReportFilters $filters): RefundSummaryData;

    /**
     * @return LengthAwarePaginator<int, RefundLinkageRow>
     *
     * @throws AuthorizationException
     */
    public function refundLinkage(User $user, ReportingPeriod $period, ReportFilters $filters, int $perPage = 25): LengthAwarePaginator;

    /** @throws AuthorizationException */
    public function paymentSummary(User $user, ReportingPeriod $period, ReportFilters $filters): PaymentFinancialSummaryData;

    /**
     * @return LengthAwarePaginator<int, ReconciliationIssueRow>
     *
     * @throws AuthorizationException
     */
    public function paymentReconciliationIssues(User $user, int $perPage = 25): LengthAwarePaginator;

    /** @throws AuthorizationException */
    public function instructorFinancialSummary(User $user, ReportingPeriod $period, ReportFilters $filters): InstructorFinancialSummaryData;

    public function freshness(ReportingPeriod $period): OperationsReportFreshnessData;

    public function canViewOverview(User $user): bool;

    public function canViewWallet(User $user): bool;

    public function canViewPayments(User $user): bool;

    public function canViewInstructorFinancials(User $user): bool;

    /** @throws AuthorizationException */
    public function rechargeMonitoringSummary(User $user): WalletRechargeMonitoringSummary;

    /**
     * @param  array{status?: ?string, provider?: ?string, currencyCode?: ?string, reference?: ?string, capturedUncreditedOnly?: bool, staleOnly?: bool}  $params
     * @return LengthAwarePaginator<int, WalletRechargeMonitoringRow>
     *
     * @throws AuthorizationException
     */
    public function paginatedRechargeMonitoring(User $user, array $params, ?ReportingPeriod $period = null, int $perPage = 25): LengthAwarePaginator;

    public function canViewRechargeMonitoring(User $user): bool;
}
