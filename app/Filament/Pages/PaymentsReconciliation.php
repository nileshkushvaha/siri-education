<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HasFinancialReportFilters;
use App\Reporting\Contracts\FinancialReportsServiceInterface;
use App\Reporting\Contracts\ReportAccessContextInterface;
use App\Reporting\Contracts\ReportRegistryInterface;
use App\Reporting\DTOs\Finance\PaymentFinancialSummaryData;
use App\Reporting\DTOs\Operations\OperationsReportFreshnessData;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Phase 18E — Payments & Reconciliation report (§13/§14). Requires `ViewPaymentReports`. */
class PaymentsReconciliation extends Page
{
    use HasFinancialReportFilters;

    protected string $view = 'filament.pages.payments-reconciliation';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?string $navigationLabel = 'Payments & Reconciliation';

    protected static ?string $title = 'Payments & Reconciliation';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 11;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        $definition = app(ReportRegistryInterface::class)->find('payment_outcomes');

        return $definition !== null && app(ReportAccessContextInterface::class)->canView($user, $definition);
    }

    public function summary(): PaymentFinancialSummaryData
    {
        return app(FinancialReportsServiceInterface::class)->paymentSummary(auth()->user(), $this->period(), $this->filters());
    }

    public function reconciliationIssues(): LengthAwarePaginator
    {
        return app(FinancialReportsServiceInterface::class)->paymentReconciliationIssues(auth()->user(), 10);
    }

    public function freshness(): OperationsReportFreshnessData
    {
        return app(FinancialReportsServiceInterface::class)->freshness($this->period());
    }
}
