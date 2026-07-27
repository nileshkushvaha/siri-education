<?php

declare(strict_types=1);

namespace App\Reporting\Services;

use App\Models\User;
use App\Reporting\Contracts\FinancialReportsServiceInterface;
use App\Reporting\Contracts\ReportAccessContextInterface;
use App\Reporting\Contracts\ReportRegistryInterface;
use App\Reporting\DTOs\Finance\InstructorFinancialSummaryData;
use App\Reporting\DTOs\Finance\PaymentFinancialSummaryData;
use App\Reporting\DTOs\Finance\RefundSummaryData;
use App\Reporting\DTOs\Finance\WalletFinancialSummaryData;
use App\Reporting\DTOs\Finance\WalletRechargeMonitoringSummary;
use App\Reporting\DTOs\Operations\OperationsReportFreshnessData;
use App\Reporting\Enums\ReportDataFreshness;
use App\Reporting\Filters\ReportFilters;
use App\Reporting\Repositories\InstructorFinancialReportRepository;
use App\Reporting\Repositories\PaymentFinancialReportRepository;
use App\Reporting\Repositories\WalletFinancialReportRepository;
use App\Reporting\ValueObjects\ReportingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/** See the interface for the permission and read-only contract. */
final class FinancialReportsService implements FinancialReportsServiceInterface
{
    public function __construct(
        private readonly WalletFinancialReportRepository $wallets,
        private readonly PaymentFinancialReportRepository $payments,
        private readonly InstructorFinancialReportRepository $instructorFinancials,
        private readonly ReportAccessContextInterface $access,
        private readonly ReportRegistryInterface $registry,
    ) {}

    public function walletSummary(User $user, ReportingPeriod $period, ReportFilters $filters): WalletFinancialSummaryData
    {
        $this->authorize($user, 'wallet_activity', 'ViewWalletReports');

        return $this->wallets->summary($period, $this->restrict($filters, 'wallet_activity'));
    }

    public function refundSummary(User $user, ReportingPeriod $period, ReportFilters $filters): RefundSummaryData
    {
        $this->authorize($user, 'refund_report', 'ViewWalletReports');

        return $this->wallets->refundSummary($period, $this->restrict($filters, 'refund_report'));
    }

    public function refundLinkage(User $user, ReportingPeriod $period, ReportFilters $filters, int $perPage = 25): LengthAwarePaginator
    {
        $this->authorize($user, 'refund_report', 'ViewWalletReports');

        return $this->wallets->paginatedRefundLinkage($period, $perPage);
    }

    public function paymentSummary(User $user, ReportingPeriod $period, ReportFilters $filters): PaymentFinancialSummaryData
    {
        $this->authorize($user, 'payment_outcomes', 'ViewPaymentReports');

        return $this->payments->summary($period, $this->restrict($filters, 'payment_outcomes'));
    }

    public function paymentReconciliationIssues(User $user, int $perPage = 25): LengthAwarePaginator
    {
        $this->authorize($user, 'payment_outcomes', 'ViewPaymentReports');

        return $this->payments->paginatedReconciliationIssues($user, $perPage);
    }

    public function instructorFinancialSummary(User $user, ReportingPeriod $period, ReportFilters $filters): InstructorFinancialSummaryData
    {
        $this->authorize($user, 'earnings_settlements', 'ViewInstructorCompensationReports');

        return $this->instructorFinancials->summary($period, $this->restrict($filters, 'earnings_settlements'));
    }

    public function freshness(ReportingPeriod $period): OperationsReportFreshnessData
    {
        return new OperationsReportFreshnessData(
            freshness: ReportDataFreshness::Live,
            generatedAt: CarbonImmutable::now(),
            reportingTimezone: $period->timezone,
            periodLabel: $period->label,
        );
    }

    public function canViewOverview(User $user): bool
    {
        return $this->allowed($user, 'finance_overview', 'ViewFinanceReports');
    }

    public function canViewWallet(User $user): bool
    {
        return $this->allowed($user, 'wallet_activity', 'ViewWalletReports');
    }

    public function canViewPayments(User $user): bool
    {
        return $this->allowed($user, 'payment_outcomes', 'ViewPaymentReports');
    }

    public function canViewInstructorFinancials(User $user): bool
    {
        return $this->allowed($user, 'earnings_settlements', 'ViewInstructorCompensationReports');
    }

    public function rechargeMonitoringSummary(User $user): WalletRechargeMonitoringSummary
    {
        $this->authorize($user, 'recharge_monitoring', 'ViewWalletReports');

        return $this->wallets->rechargeMonitoringSummary();
    }

    public function paginatedRechargeMonitoring(User $user, array $params, ?ReportingPeriod $period = null, int $perPage = 25): LengthAwarePaginator
    {
        $this->authorize($user, 'recharge_monitoring', 'ViewWalletReports');

        if ($period !== null) {
            $params['periodStartUtc'] = $period->startUtc;
            $params['periodEndUtcExclusive'] = $period->endUtcExclusive;
        }

        return $this->wallets->paginatedRechargeMonitoring($params, ! $this->access->canViewFullStudentIdentity($user), $perPage);
    }

    public function canViewRechargeMonitoring(User $user): bool
    {
        return $this->allowed($user, 'recharge_monitoring', 'ViewWalletReports');
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function restrict(ReportFilters $filters, string $reportKey): ReportFilters
    {
        $definition = $this->registry->find($reportKey);

        return $filters->restrictedTo($definition?->supportedFilters ?? []);
    }

    /** @throws AuthorizationException */
    private function authorize(User $user, string $reportKey, string $permission): void
    {
        if (! $this->allowed($user, $reportKey, $permission)) {
            throw new AuthorizationException('You may not view this financial report.');
        }
    }

    private function allowed(User $user, string $reportKey, string $permission): bool
    {
        $definition = $this->registry->find($reportKey);

        if ($definition === null || ! $this->access->canView($user, $definition)) {
            return false;
        }

        return $this->hasPermission($user, $permission);
    }

    private function hasPermission(User $user, string $permission): bool
    {
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        try {
            return $user->hasPermissionTo($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}
