<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HasFinancialReportFilters;
use App\Reporting\Contracts\FinancialReportsServiceInterface;
use App\Reporting\Contracts\ReportAccessContextInterface;
use App\Reporting\Contracts\ReportRegistryInterface;
use App\Reporting\DTOs\Finance\RefundSummaryData;
use App\Reporting\DTOs\Finance\WalletFinancialSummaryData;
use App\Reporting\DTOs\Operations\OperationsReportFreshnessData;
use App\Wallet\Enums\WalletLedgerEntryType;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/** Phase 18E — Wallet & Refunds report (§12/§15). Requires `ViewWalletReports`. */
class WalletRefunds extends Page
{
    use HasFinancialReportFilters;

    protected string $view = 'filament.pages.wallet-refunds';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static ?string $navigationLabel = 'Wallet & Refunds';

    protected static ?string $title = 'Wallet & Refunds';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 10;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        $definition = app(ReportRegistryInterface::class)->find('wallet_activity');

        return $definition !== null && app(ReportAccessContextInterface::class)->canView($user, $definition);
    }

    public function summary(): WalletFinancialSummaryData
    {
        return app(FinancialReportsServiceInterface::class)->walletSummary(auth()->user(), $this->period(), $this->filters());
    }

    public function refundSummary(): RefundSummaryData
    {
        return app(FinancialReportsServiceInterface::class)->refundSummary(auth()->user(), $this->period(), $this->filters());
    }

    public function refundLinkage(): LengthAwarePaginator
    {
        return app(FinancialReportsServiceInterface::class)->refundLinkage(auth()->user(), $this->period(), $this->filters(), 10);
    }

    public function freshness(): OperationsReportFreshnessData
    {
        return app(FinancialReportsServiceInterface::class)->freshness($this->period());
    }

    /** @return Collection<int, WalletLedgerEntryType> */
    public function entryTypeOptions(): Collection
    {
        return collect(WalletLedgerEntryType::cases());
    }
}
