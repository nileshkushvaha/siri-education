<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Navigation\Concerns\HasCentralizedNavigation;
use App\Filament\Pages\Concerns\HasFinancialReportFilters;
use App\Reporting\Contracts\FinancialReportsServiceInterface;
use App\Reporting\Contracts\ReportAccessContextInterface;
use App\Reporting\Contracts\ReportRegistryInterface;
use App\Reporting\DTOs\Finance\InstructorFinancialSummaryData;
use App\Reporting\DTOs\Operations\OperationsReportFreshnessData;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Instructor Earnings, Settlements & Withdrawals (SRS §17/§18).
 * Requires `ViewInstructorCompensationReports` — the strictest financial
 * permission; never implied by general finance/wallet/payment access.
 */
class InstructorFinancials extends Page
{
    use HasCentralizedNavigation;
    use HasFinancialReportFilters;

    protected string $view = 'filament.pages.instructor-financials';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyRupee;

    protected static ?string $navigationLabel = 'Instructor Financials';

    protected static ?string $title = 'Instructor Earnings, Settlements & Withdrawals';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 12;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        $definition = app(ReportRegistryInterface::class)->find('earnings_settlements');

        return $definition !== null && app(ReportAccessContextInterface::class)->canView($user, $definition);
    }

    public function summary(): InstructorFinancialSummaryData
    {
        return app(FinancialReportsServiceInterface::class)->instructorFinancialSummary(auth()->user(), $this->period(), $this->filters());
    }

    public function freshness(): OperationsReportFreshnessData
    {
        return app(FinancialReportsServiceInterface::class)->freshness($this->period());
    }
}
