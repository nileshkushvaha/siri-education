<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HasFinancialReportFilters;
use App\Reporting\Contracts\FinancialReportsServiceInterface;
use App\Reporting\Contracts\ReportAccessContextInterface;
use App\Reporting\Contracts\ReportRegistryInterface;
use App\Reporting\DTOs\Finance\InstructorFinancialSummaryData;
use App\Reporting\DTOs\Finance\PaymentFinancialSummaryData;
use App\Reporting\DTOs\Finance\RefundSummaryData;
use App\Reporting\DTOs\Finance\WalletFinancialSummaryData;
use App\Reporting\DTOs\Operations\OperationsReportFreshnessData;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Phase 18E — Finance Overview (§11). Opening requires
 * `ViewFinanceReports`; every embedded section independently re-checks
 * its own stricter permission through the service — an overview user
 * without wallet/payment/compensation permission sees that section
 * withheld, and its queries never run. §7 Outcome B: no "revenue"
 * total exists anywhere; collections, commercial value and wallet
 * consumption are separate, never summed, never cross-currency.
 */
class FinanceOverview extends Page
{
    use HasFinancialReportFilters;

    protected string $view = 'filament.pages.finance-overview';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Finance Overview';

    protected static ?string $title = 'Finance Overview';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 9;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        $definition = app(ReportRegistryInterface::class)->find('finance_overview');

        return $definition !== null && app(ReportAccessContextInterface::class)->canView($user, $definition);
    }

    public function canViewWalletSection(): bool
    {
        return app(FinancialReportsServiceInterface::class)->canViewWallet(auth()->user());
    }

    public function canViewPaymentSection(): bool
    {
        return app(FinancialReportsServiceInterface::class)->canViewPayments(auth()->user());
    }

    public function canViewCompensationSection(): bool
    {
        return app(FinancialReportsServiceInterface::class)->canViewInstructorFinancials(auth()->user());
    }

    public function walletSummary(): ?WalletFinancialSummaryData
    {
        return $this->canViewWalletSection()
            ? app(FinancialReportsServiceInterface::class)->walletSummary(auth()->user(), $this->period(), $this->filters())
            : null;
    }

    public function refundSummary(): ?RefundSummaryData
    {
        return $this->canViewWalletSection()
            ? app(FinancialReportsServiceInterface::class)->refundSummary(auth()->user(), $this->period(), $this->filters())
            : null;
    }

    public function paymentSummary(): ?PaymentFinancialSummaryData
    {
        return $this->canViewPaymentSection()
            ? app(FinancialReportsServiceInterface::class)->paymentSummary(auth()->user(), $this->period(), $this->filters())
            : null;
    }

    public function instructorSummary(): ?InstructorFinancialSummaryData
    {
        return $this->canViewCompensationSection()
            ? app(FinancialReportsServiceInterface::class)->instructorFinancialSummary(auth()->user(), $this->period(), $this->filters())
            : null;
    }

    public function freshness(): OperationsReportFreshnessData
    {
        return app(FinancialReportsServiceInterface::class)->freshness($this->period());
    }
}
